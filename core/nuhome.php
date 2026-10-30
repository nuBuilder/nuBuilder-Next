<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * The globeadmin's start page (pages/nuhome.php): built-in tiles, own tiles, groups, display and size – one layout for the
 * installation, stored as JSON in zzzzsys_nunext_setting (id home_layout). Without a stored layout the default groups apply.
 * Customize mode: assets/js/nuhome.js. $nuConfigGlobeadminHome (nuBuilder's setting) can replace the page by a launch form.
 *
 * Layout: {v, display: groups|tabs, size: large|compact|list, tabs: [{id, title}], groups: [{id, title, tab, tiles: [key …], color?}], hidden: [key …],
 *          custom: {key: {label, hint, icon, color, kind: form|page|procedure|url, target, record, run, newtab}}, view: {…}}
 * Display tabs: the tabs hold groups (group.tab); without tabs each group is a tab of its own.
 * View (0.8.420, the View panel of the start page): how the tiles look – style, descriptions, navigation, look, per tab (tabStyle) and per
 * screen size (sizes.tablet / sizes.phone); size and display are derived from it (compatibility: hook variables, Customise). People
 * without the right to change the layout keep their own changes in an overlay per layout (overlays(), not in the layout).
 *
 * User start pages (0.8.425): users without an admin role get a tile page too – one layout per access level (list key home_user,
 * access_ids = [access id]) or the default for all access levels (access_ids = ["*"]); without either, an automatic layout of the
 * forms and reports of the access level (autoLayout()). Only own tiles (c_…: form, report, procedure, link, a few pages), each tile
 * and group optionally with conditions (cond: permission items, team, department, position). Edited by "Start page layout"
 * admins on ?p=home&for=<access id | *> (forLevel(…, preview: true) – rights of that access level, conditions not applied, marked).
 * Users keep their own view, favourites and hidden tiles in their overlay (fav / hide).
 */
final class NuHome
{
	public const SETTING = 'home_layout';
	public const DISPLAYS = ['groups', 'tabs'];
	public const SIZES = ['large', 'compact', 'list', 'listwide', 'list2'];   // list2 = list in two columns (0.8.363); 'largeinfo' of 0.8.352 was removed in 0.8.355 (stored: becomes 'large')
	public const KINDS = ['form', 'page', 'procedure', 'url', 'report'];
	/** User start pages (0.8.425): list key, default layout id in access_ids, pages a user tile may open, condition kinds. */
	public const LIST_USER = 'home_user';
	public const ALL_LEVELS = '*';
	public const USER_PAGES = ['help', 'password', 'security', 'notifications'];
	public const COND_KINDS = ['permission', 'team', 'department', 'position'];
	/** nuBuilder Next pages an own tile may open (kind page). */
	public const PAGES = ['users', 'access', 'permissions', 'sessions', 'forms', 'procedures', 'reports', 'sqlbuilder', 'prompts', 'apiclients', 'apiplayground', 'webhooks', 'email', 'emailtemplates',
		'debug', 'log', 'audit', 'update', 'settings', 'branding', 'themes', 'dashboards', 'vendor', 'sample', 'import', 'cloner', 'appcloner', 'files', 'items', 'formats', 'translations', 'help', 'security', 'password',
		'notifications', 'securitycheck', 'setupwizard', 'configfile', 'globalcode', 'compat', 'adminroles', 'tutorial', 'userfields'];
	/** Groups of the former Admin tools page (0.8.316) in the default layout. */
	public const ADMIN_GROUPS = [
		'g_adm_system' => ['home_group_system', ['tutorial', 'update', 'securitycheck', 'settings', 'globalcode', 'configfile', 'setupwizard', 'vendor', 'todo', 'selftest', 'dbreset']],
		'g_adm_look'   => ['home_group_look', ['branding', 'themes', 'dashboards']],
		'g_adm_data'   => ['home_group_data', ['pma', 'tfm', 'import', 'cloner', 'appcloner', 'sample']],
		'g_adm_int'    => ['home_group_integration', ['apiclients', 'webhooks', 'prompts', 'compat']],
	];
	/**
	 * Start page profiles (0.8.385): ready-made layouts for Customise → "Start from" and Save as new layout → "Based on".
	 * standard / all are built in presetLayout(); the others: display, size, tabs [id, title key], groups [id, title key ('' = none),
	 * tab id ('' = none), tile keys], favourites. Tiles a profile does not place are hidden (presetLayout()). Label and description:
	 * home_pf_<id>, home_pf_<id>_hint.
	 */
	public const PRESETS = [
		'standard'    => ['icon' => 'bi-house'],
		'all'         => ['icon' => 'bi-grid-3x3-gap'],
		'developer'   => ['icon' => 'bi-code-slash', 'display' => 'tabs', 'size' => 'compact', 'view' => ['style' => 'compact', 'nav' => 'tabs', 'desc' => 'hovercard'],
			'tabs' => [['t_build', 'home_pf_t_build'], ['t_debug', 'home_pf_t_debug'], ['t_int', 'home_group_integration']],
			'groups' => [
				['g_forms', 'nav_forms', 't_build', ['forms', 's_form_new', 's_form_clone', 's_snippets', 's_items', 's_files', 's_formats', 's_translations']],
				['g_code', 'home_pf_g_code', 't_build', ['procedures', 's_proc_new', 'reports', 's_report_new', 's_sqlbuilder', 'prompts']],
				// the pages Debug and Log – not the tile "Log" (it opens Debug; next to the page Log there would be two tiles called "Log")
				['g_debug', 'home_pf_g_debugging', 't_debug', ['s_debug', 's_log', 's_audit', 'sessions']],
				['g_data', 'home_group_data', 't_debug', ['pma', 'tfm', 'import', 'cloner', 'appcloner', 'sample']],
				['g_devmode', 'home_pf_g_devmode', 't_debug', ['todo', 'selftest', 'dbreset']],
				['g_int', 'home_group_integration', 't_int', ['apiclients', 's_api_playground', 'webhooks', 'compat', 's_mail_test']],
			],
			'fav' => ['forms', 'procedures', 's_debug', 'pma']],
		'minimal'     => ['icon' => 'bi-dash-square', 'display' => 'groups', 'size' => 'list', 'view' => ['style' => 'list'],
			'groups' => [['g_main', '', '', ['forms', 'procedures', 'reports', 'users', 'debug', 'settings']]]],
		'start'       => ['icon' => 'bi-mortarboard', 'display' => 'groups', 'size' => 'large',
			'groups' => [['g_learn', 'home_pf_g_learn', '', ['tutorial', 'setupwizard', 'sample']],
				['g_setup', 'home_pf_g_setup', '', ['settings', 'branding', 'securitycheck']],
				['g_build', 'home_pf_t_build', '', ['users', 'forms']]]],
		'security'    => ['icon' => 'bi-shield-lock', 'display' => 'groups', 'size' => 'large',
			'groups' => [['g_people', 'home_group_users', '', ['users', 's_user_new', 'access', 'permissions', 'adminroles']],
				['g_watch', 'home_pf_g_monitoring', '', ['sessions', 's_audit', 's_log', 'notifications', 'securitycheck']]]],
		'operations'  => ['icon' => 'bi-activity', 'display' => 'groups', 'size' => 'compact', 'view' => ['style' => 'compact'],
			'groups' => [['g_health', 'home_pf_g_health', '', ['vendor', 'securitycheck', 'update', 's_mail_test']],
				['g_config', 'home_pf_g_config', '', ['settings', 'globalcode', 'configfile', 'branding']],
				['g_tools', 'home_pf_g_tools', '', ['pma', 'tfm', 's_log', 'sessions', 's_mail_log']]]],
		'reporting'   => ['icon' => 'bi-bar-chart', 'display' => 'groups', 'size' => 'large',
			'groups' => [['g_reports', 'rpt_title_list', '', ['reports', 's_report_new', 's_sqlbuilder', 's_sqlbuilder_new']],
				['g_dash', 'dash_title', '', ['dashboards', 's_dash_new']],
				['g_rdata', 'home_group_data', '', ['pma', 'import']]]],
		'integration' => ['icon' => 'bi-plug', 'display' => 'groups', 'size' => 'compact', 'view' => ['style' => 'compact'],
			'groups' => [['g_api', 'home_pf_g_api', '', ['apiclients', 's_api_new', 's_api_playground', 'webhooks', 's_wh_calls', 'compat']],
				['g_mail', 'nav_email', '', ['email', 's_mail_settings', 's_mail_templates', 's_mail_test', 's_mail_log']],
				['g_msg', 'home_pf_g_messages', '', ['notifications', 'prompts']]]],
		'blank'       => ['icon' => 'bi-square', 'display' => 'groups', 'size' => 'large', 'groups' => [['g_own', 'home_pf_g_own', '', []]]],
	];
	/** View of a layout (0.8.420): allowed values and defaults – see the class comment. */
	public const VIEW_STYLES = ['large', 'cards', 'compact', 'list', 'launcher', 'panels', 'table'];
	public const VIEW_DESCS = ['auto', 'inline', 'tooltip', 'hovercard', 'info', 'off'];
	public const VIEW_NAVS = ['groups', 'tabs', 'sidebar', 'none'];
	public const VIEW_DENSITIES = ['comfortable', 'cozy', 'dense'];
	public const VIEW_ICONS = ['soft', 'solid', 'outline', 'mono', 'none'];
	public const VIEW_LIST_COLS = ['1', '2', '3', '4', 'auto'];   // 3 / 4 (0.8.659): fewer on narrow screens (nuhome.css)
	public const VIEW_LIST_WIDTHS = ['readable', 'full'];
	public const VIEW_LINES = [0, 1, 2, 3];
	public const VIEW_DELAYS = [0, 300, 800];
	public const VIEW_SIZES = ['tablet', 'phone'];
	public const VIEW_DEFAULTS = ['style' => 'large', 'listCols' => '1', 'listWidth' => 'readable', 'desc' => 'auto', 'lines' => 2, 'delay' => 300, 'nav' => 'groups',
		'density' => 'comfortable', 'icon' => 'soft', 'groupColors' => false, 'favStrip' => false, 'recentStrip' => false, 'badges' => true, 'actions' => true];
	/** Colours of groups without an own colour (by position) when "Colour per group" is on. */
	public const GROUP_COLORS = ['#5b5bd6', '#2f6fd6', '#0f8f86', '#5f6b7a', '#c2417a', '#c27803', '#7a4fd0'];
	/** Personal view per layout: users in sus_json, the globeadmin in a setting row, demo visitors in the session. */
	private const VIEW_JSON = 'NUNEXT_HOME_VIEW';
	private const VIEW_GA = 'home_view_ga';
	/** Most layouts with a personal view per person; size of one entry (JSON). */
	public const VIEW_OVERLAYS_MAX = 20;
	public const VIEW_OVERLAY_BYTES = 4096;
	/** Version of the stored layout (2 since 0.8.316 – upgrade baseline 0.8.321: no conversion of older layouts). */
	public const VERSION = 2;
	/** Icons offered in the tile editor (any bi-… icon may be typed). */
	public const ICONS = ['bi-star', 'bi-star-fill', 'bi-house', 'bi-people', 'bi-person', 'bi-person-badge', 'bi-building', 'bi-briefcase', 'bi-cart', 'bi-bag',
		'bi-box-seam', 'bi-truck', 'bi-receipt', 'bi-cash-coin', 'bi-currency-euro', 'bi-graph-up', 'bi-bar-chart', 'bi-pie-chart', 'bi-calendar3', 'bi-clock',
		'bi-clipboard-check', 'bi-check2-square', 'bi-list-check', 'bi-table', 'bi-grid', 'bi-ui-checks-grid', 'bi-journal-text', 'bi-file-earmark-text',
		'bi-folder2-open', 'bi-archive', 'bi-envelope', 'bi-chat-dots', 'bi-telephone', 'bi-geo-alt', 'bi-globe', 'bi-link-45deg', 'bi-box-arrow-up-right',
		'bi-gear', 'bi-sliders', 'bi-tools', 'bi-wrench', 'bi-shield-lock', 'bi-key', 'bi-lock', 'bi-database', 'bi-server', 'bi-cloud', 'bi-code-slash',
		'bi-terminal', 'bi-filetype-php', 'bi-play-fill', 'bi-lightning', 'bi-bell', 'bi-flag', 'bi-bookmark', 'bi-heart', 'bi-lightbulb', 'bi-question-circle',
		'bi-info-circle', 'bi-printer', 'bi-download', 'bi-upload', 'bi-search', 'bi-plus-circle'];

	// ------------------------------------------------------------------ built-in tiles

	/** key => [route (string, [page, params] or ['post' => app]), icon, label, hint]; only what this installation offers. */
	public static function catalog(): array
	{
		$c = [
			'users'       => ['users', 'bi-people', t('nav_users'), t('home_users_hint')],
			'access'      => ['access', 'bi-shield-lock', t('nav_access'), t('home_access_hint')],
			'permissions' => ['permissions', 'bi-key', t('perm_title'), t('home_perm_hint')],
			'sessions'    => ['sessions', 'bi-people-fill', t('sess_title'), t('home_sessions_hint')],
			'adminroles'  => ['adminroles', 'bi-person-badge', t('adm_title'), t('adm_card_hint')],
			'userfields'  => ['userfields', 'bi-ui-checks', t('uf_title'), t('uf_card_hint')],   // custom user fields (0.8.454)
			'forms'       => ['forms', 'bi-ui-checks-grid', t('nav_forms'), t('home_forms_hint')],
			'procedures'  => ['procedures', 'bi-filetype-php', t('proc_title'), t('home_proc_hint')],
			'reports'     => ['reports', 'bi-file-earmark-richtext', t('rpt_title_list'), t('home_reports_hint')],
			'prompts'     => ['prompts', 'bi-stars', t('pg_title'), t('home_prompts_hint')],
			'apiclients'  => ['apiclients', 'bi-plug', t('api_title'), t('home_api_hint')],
			'webhooks'    => ['webhooks', 'bi-broadcast', t('wh_title'), t('home_wh_hint')],
			'email'       => ['email', 'bi-envelope', t('nav_email'), t('home_email_hint')],
			'notifications' => [['notifications', ['tab' => 'manage']], 'bi-bell', t('ntf_title'), t('home_ntf_hint')],
		];
		// nuBuilder Forte's User Home (0.8.602): the launch form nuuserhome with the run buttons of the New form wizard
		if (NuUserHome::available()) $c['userhome'] = [['form', ['f' => NuUserHome::FORM]], 'bi-house-door', t('home_userhome'), t('home_userhome_hint')];
		if (isset(nu_forms()['nunotes'])) $c['notes'] = [['form', ['f' => 'nunotes']], 'bi-journal-richtext', t('nav_notes'), t('home_notes_hint')];
		$c['debug'] = ['debug', 'bi-journal-text', t('nav_log'), t('home_log_hint')];
		$c['update'] = ['update', 'bi-cloud-arrow-up', t('upd_title'), t('home_update_hint')];
		$c['settings'] = ['settings', 'bi-sliders', t('set_title'), t('home_settings_hint')];
		// Database (key 'pma' as before): the primary tool – phpMyAdmin or Adminer (0.8.282, ▾ = the other one, NuHome::actions());
		// File Manager. Opened like in the user menu (POST, new tab); not copied yet → the Admin tools page explains it
		$dbTools = NuVendor::dbTools();
		if (nu_db()->type === 'mysql' || $dbTools) {
			$c['pma'] = $dbTools ? [['post' => 'DB'], 'bi-database', t('home_db'), NuVendor::DB_TOOLS[$dbTools[0]]]
				: ['vendor', 'bi-database', t('home_db'), 'phpMyAdmin / Adminer – ' . t('home_tool_missing')];
		}
		// File Manager (0.8.479): the page nuFiles (core/nufilemanager.php) – key 'tfm' kept, so saved layouts keep the tile
		if (NuFileManager::shipped()) $c['tfm'] = ['filemanager', 'bi-folder2-open', t('vendor_tfm'), t('fm_tile_desc')];
		// the former Admin tools page (0.8.316): its tools are tiles now; 'vendor' = System status (InnoDB, demo mode, missing tools)
		$b = self::badges();
		$c['update'][4] = $b['update'] ?? null;
		$c += [
			'securitycheck' => ['securitycheck', 'bi-shield-check', t('secchk_title'), t('secchk_card_hint')],
			'configfile'    => ['configfile', 'bi-filetype-php', t('cfg_tile'), t('cfg_card_hint')],   // short name (0.8.659) – the description names nuconfig.php
			'globalcode'     => ['globalcode', 'bi-code-square', t('scode_title'), t('scode_card_hint')],
			'setupwizard'   => ['setupwizard', 'bi-magic', t('wiz_title'), t('wiz_card_hint')],
			'branding'      => ['branding', 'bi-palette', t('brand_title'), t('brand_card_hint')],
			'themes'        => ['themes', 'bi-brush', t('theme_title'), t('theme_card_hint')],
			'dashboards'    => ['dashboards', 'bi-speedometer2', t('dash_title'), t('dash_card_hint')],
			'import'        => ['import', 'bi-filetype-csv', t('imp_title'), t('imp_card_hint')],
			'cloner'        => ['cloner', 'bi-files', t('cl_title'), t('cl_card_hint')],   // 0.8.633: a tile of its own (before: only Forms ▾ → Clone form)
			'appcloner'     => ['appcloner', 'bi-copy', t('ac_title'), t('ac_card_hint')],
			'sample'        => ['sample', 'bi-box-seam', t('smp_title'), t('smp_card_hint'), $b['sample'] ?? null],
			'compat'        => ['compat', 'bi-puzzle', t('compat_title'), t('compat_hint')],
		];
		$c['vendor'] = ['vendor', 'bi-activity', t('status_title'), t('status_hint'), $b['vendor'] ?? null];
		$c['tutorial'] = ['tutorial', 'bi-mortarboard', t('tut_title'), t('tut_card_hint'), self::tutorialBadge()];
		if (NuHash::devMode()) $c['todo'] = ['todo', 'bi-list-check', t('todo_title'), t('todo_card_hint'), ['Dev', 'secondary']];
		if (NuHash::devMode()) $c['selftest'] = ['selftest', 'bi-clipboard2-pulse', t('st_title'), t('st_card_hint'), ['Dev', 'secondary']];   // 0.8.361
		if (NuHash::devMode() && NuDbReset::available()) $c['dbreset'] = ['dbreset', 'bi-database-x', t('dbr_title'), t('dbr_card_hint'), ['Dev', 'secondary']];   // 0.8.628
		return $c;
	}

	/** Tutorial tile: lessons done of all (0.8.328). */
	private static function tutorialBadge(): ?array
	{
		try {
			if (!NuTutorial::allowed()) return null;
			[$d, $n] = NuTutorial::counts();
			return $n ? [$d >= $n ? '✓' : $d . ' / ' . $n, $d >= $n ? 'success' : 'secondary'] : null;
		} catch (Throwable) {
			return null;
		}
	}

	/** Badges of built-in tiles: key => [text, colour] (Bootstrap text-bg-…). */
	public static function badges(): array
	{
		static $b = null;
		if ($b !== null) return $b;
		$b = [];                                                // no version on the Update tile (0.8.332)
		try {
			$smp = NuSample::status();
			if ($smp['installed'] && $smp['version'] < $smp['current']) $b['sample'] = [t('smp_update_available'), 'warning'];
		} catch (Throwable) { /* no sample data */ }
		try {                                                   // a newer release on GitHub (0.8.374, NuGitHub – looked up once a day)
			if (NuAdmin::can('update', 'view')) NuGitHub::autoCheck();   // once a day, only for those who may update (a page view of anybody else never waits for GitHub)
			$gh = NuGitHub::newer();
			if ($gh) $b['update'] = [t('gh_badge_new', (string)$gh['version']), 'success'];
		} catch (Throwable) { /* no settings table */ }
		$n = self::statusWarnings();
		$b['vendor'] = $n > 0 ? [t('status_badge_warn', $n), 'warning'] : [t('status_badge_ok'), 'success'];
		return $b;
	}

	/**
	 * Points of System status (?p=vendor) that need a look: time zone of the server only, no database tool, File Manager missing,
	 * tables that are not InnoDB, demo users with problems. Kept in the session for 5 minutes (information_schema can be slow);
	 * the System status page sets it fresh.
	 */
	public static function statusWarnings(bool $fresh = false): int
	{
		$c = $_SESSION['nu_status_warn'] ?? null;
		if (!$fresh && is_array($c) && ($c[0] ?? 0) > time() - 300) return (int)$c[1];
		$n = 0;
		try {
			$mysql = nu_db()->type === 'mysql';
			if (nu_timezone()[1] === 'server') $n++;
			if ($mysql && !NuVendor::dbTools()) $n++;
			if ($mysql && (int)nu_db()->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND COALESCE(ENGINE, '') <> 'InnoDB'") > 0) $n++;
			foreach (NuDemo::status() as $d) if ($d['problems']) { $n++; break; }
		} catch (Throwable) { /* best effort */ }
		$_SESSION['nu_status_warn'] = [time(), $n];
		return $n;
	}

	/**
	 * Pages and actions behind the tiles (0.8.321): the "+" of a tile and the entries of its "▾" menu. Each can be pinned as a tile of
	 * its own (pin in the menu, or Customize → Add tile → built-in), is found by the search and by Ctrl+K – but is never placed on the
	 * start page by itself. key (prefix s_, or an existing tile key) => [url, icon, label, parent tile key].
	 */
	public static function subTiles(): array
	{
		static $s = null;
		if ($s !== null) return $s;
		$forms = nu_forms();
		$s = [
			's_user_new'      => [nu_url('user'), 'bi-person-plus', t('user_new'), 'users'],
			's_access_new'    => [nu_url('accesslevel'), 'bi-plus-lg', t('access_new'), 'access'],
			's_user_home'     => [nu_url('home', ['for' => self::ALL_LEVELS]), 'bi-grid-1x2', t('home_u_title'), 'access'],   // user start pages (0.8.425)
			's_perm_new'      => [nu_url('permissions', ['id' => 'new']), 'bi-plus-lg', t('perm_new'), 'permissions'],
			's_proc_new'      => [nu_url('procedures', ['id' => 'new']), 'bi-plus-lg', t('proc_new'), 'procedures'],
			's_prompt_new'    => [nu_url('prompts', ['id' => 'new']), 'bi-plus-lg', t('pg_new'), 'prompts'],
			's_dash_new'      => [nu_url('dashboards', ['id' => 'new']), 'bi-plus-lg', t('dash_new'), 'dashboards'],
			's_report_new'    => [nu_url('reports', ['id' => 'new']), 'bi-plus-lg', t('rpt_new'), 'reports'],
			's_sqlbuilder'    => [nu_url('sqlbuilder'), 'bi-diagram-3', t('sqb_title'), 'reports'],
			's_sqlbuilder_new' => [nu_url('sqlbuilder', ['id' => 'new']), 'bi-plus-lg', t('sqb_new'), 'reports'],
			's_form_new'      => [nu_url('builder', ['new' => '1']), 'bi-plus-lg', t('builder_new_form'), 'forms'],
			's_form_clone'    => [nu_url('cloner'), 'bi-files', t('home_clone_form'), 'forms'],
			'import'          => [nu_url('import'), 'bi-filetype-csv', t('imp_title'), 'forms'],
		];
		if (isset($forms['nucodesnippet'])) $s['s_snippets'] = [nu_url('form', ['f' => 'nucodesnippet']), 'bi-file-earmark-code', t('nav_snippets'), 'forms'];
		$s += [
			's_items'         => [nu_url('items'), 'bi-list-ul', t('item_title'), 'forms'],
			's_files'         => [nu_url('files'), 'bi-images', t('file_title'), 'forms'],
			's_formats'       => [nu_url('formats'), 'bi-type', t('fmt_title'), 'forms'],
			's_translations'  => [nu_url('translations'), 'bi-translate', t('trl_title'), 'forms'],
			's_api_new'       => [nu_url('apiclients', ['id' => 'new']), 'bi-plus-lg', t('api_new'), 'apiclients'],
			's_api_playground' => [nu_url('apiplayground'), 'bi-terminal', t('apg_title'), 'apiclients'],
			's_wh_new'        => [nu_url('webhooks', ['id' => 'new']), 'bi-plus-lg', t('wh_new'), 'webhooks'],
			's_wh_calls'      => [nu_url('webhooks', ['tab' => 'calls']), 'bi-list-ul', t('wh_calls'), 'webhooks'],
			's_mail_settings' => [nu_url('email', ['tab' => 'settings']), 'bi-gear', t('mail_tab_settings'), 'email'],
			's_mail_test'     => [nu_url('email', ['tab' => 'test']), 'bi-send', t('mail_tab_test'), 'email'],
			's_mail_templates' => [nu_url('emailtemplates'), 'bi-file-earmark-richtext', t('emt_title'), 'email'],
			's_mail_template_new' => [nu_url('emailtemplates', ['id' => 'new']), 'bi-plus-lg', t('emt_new'), 'email'],
			's_mail_log'      => [nu_url('email', ['tab' => 'log']), 'bi-journal-text', t('mail_tab_log'), 'email'],
			's_debug'         => [nu_url('debug'), 'bi-bug', t('debug_title'), 'debug'],
			's_log'           => [nu_url('log'), 'bi-journal-text', t('nav_log'), 'debug'],
			's_audit'         => [nu_url('audit'), 'bi-clock-history', t('aud_title'), 'debug'],
			'sessions'        => [nu_url('sessions'), 'bi-people', t('sess_title'), 'debug'],
		];
		if (isset($forms['nunotes'])) $s['s_note_new'] = [nu_url('form', ['f' => 'nunotes', 'r' => '-1']), 'bi-plus-lg', t('home_new_note'), 'notes'];
		// settings centre (0.8.702): its sections behind the Settings tile (only those the user may open)
		foreach (NuSettingsHub::pages() as $sec => $list) $s['s_set_' . $sec] = [$list[0][2], NuSettingsHub::SECTIONS[$sec][0], t(NuSettingsHub::SECTIONS[$sec][1]), 'settings'];
		return $s;
	}

	/**
	 * Button on the right of a built-in tile instead of the ">" (0.8.141): ['add', label, url, sub key] = "+" straight to a new record,
	 * ['menu', label, [[icon, label, url, sub key], …]] = "▾" with the pages behind the tile (subTiles()). Tiles without an entry keep ">".
	 */
	public static function actions(): array
	{
		static $a = null;                                    // the same for every tile of the page
		if ($a !== null) return $a;
		$sub = self::subTiles();
		$item = static fn(string $k) => isset($sub[$k]) ? [$sub[$k][1], $sub[$k][2], $sub[$k][0], $k] : null;
		$menu = static fn(string $label, array $keys) => ['menu', $label, array_values(array_filter(array_map($item, $keys)))];
		$add = static fn(string $k) => isset($sub[$k]) ? ['add', $sub[$k][2], $sub[$k][0], $k] : null;
		$a = [];
		// both database tools: ▾ opens the other one (a menu entry ['post' => app] is sent like the tile – POST, new tab)
		if (count(NuVendor::dbTools()) > 1) $a['pma'] = ['dbmenu', t('home_db')];   // both tools: open either, star = make it the default (NuVendor::dbMenuItems)
		$a += array_filter([
			'users'       => $add('s_user_new'),
			'access'      => $menu(t('nav_access'), ['s_access_new', 's_user_home']),
			'permissions' => $add('s_perm_new'),
			'procedures'  => $add('s_proc_new'),
			'prompts'     => $add('s_prompt_new'),
			'reports'     => ['menu', t('rpt_title_list'), array_values(array_filter([['bi-file-earmark-richtext', t('rpt_title_list'), nu_url('reports'), null],
				$item('s_report_new'), $item('s_sqlbuilder'), $item('s_sqlbuilder_new')]))],
			'forms'       => $menu(t('nav_forms'), ['s_form_new', 's_form_clone', 'import', 's_snippets', 's_items', 's_files', 's_formats', 's_translations']),
			'apiclients'  => $menu(t('api_title'), ['s_api_new', 's_api_playground']),
			'webhooks'    => $menu(t('wh_title'), ['s_wh_new', 's_wh_calls']),
			'email'       => $menu(t('nav_email'), ['s_mail_settings', 's_mail_test', 's_mail_templates', 's_mail_template_new', 's_mail_log']),
			'debug'       => $menu(t('nav_log'), ['s_debug', 's_log', 's_audit', 'sessions']),
			'dashboards'  => $add('s_dash_new'),
			'notes'       => $add('s_note_new'),
			'settings'    => !NuSettingsHub::pages() ? null : $menu(t('set_title'), array_map(static fn($k) => 's_set_' . $k, array_keys(NuSettingsHub::SECTIONS))),
		]);
		return $a;
	}

	public static function defaultLayout(): array
	{
		// two tabs (display "Tabs"): the daily work and the former Admin tools page (0.8.316); display "Groups" shows all groups
		$l = ['v' => self::VERSION, 'display' => 'groups', 'size' => 'large', 'hidden' => [], 'fav' => [], 'custom' => (object)[], 'view' => self::VIEW_DEFAULTS,
			'tabs' => [['id' => 't_home', 'title' => t('nav_home')], ['id' => 't_admin', 'title' => t('vendor_title')]], 'groups' => [
			['id' => 'g_users', 'title' => t('home_group_users'), 'tab' => 't_home', 'tiles' => ['users', 'access', 'permissions', 'userfields', 'sessions', 'adminroles']],
			['id' => 'g_dev', 'title' => t('home_group_dev'), 'tab' => 't_home', 'tiles' => ['forms', 'userhome', 'procedures', 'reports', 'debug']],
			['id' => 'g_comm', 'title' => t('home_group_comm'), 'tab' => 't_home', 'tiles' => ['email', 'notes']],
		]];
		foreach (self::ADMIN_GROUPS as $id => [$title, $tiles]) $l['groups'][] = ['id' => $id, 'title' => t($title), 'tab' => 't_admin', 'tiles' => $tiles];
		return $l;
	}

	/**
	 * A profile as a layout, cleaned like a stored one (0.8.385); null = unknown id. Only tiles this installation offers; every
	 * built-in tile the profile does not place goes to "hidden" – otherwise layout() would add it to the last group again.
	 * $keepOwn: the own tiles of $from come along – in a group "My tiles" on top (own tiles hidden there stay hidden, favourites stay).
	 * Without: no own tiles (as Reset did). Mirrored in assets/js/nuhome.js (presetLayout) for the own tiles – keep both in step.
	 */
	public static function presetLayout(string $id, ?array $from = null, bool $keepOwn = false): ?array
	{
		$p = self::PRESETS[$id] ?? null;
		if ($p === null) return null;
		static $cat = null;
		$cat ??= self::catalog();
		$sub = self::subTiles();
		$exists = static fn($k) => isset($cat[$k]) || isset($sub[$k]);
		if ($id === 'standard' || $id === 'all') {
			$l = self::defaultLayout();
			if ($id === 'all') { $l['size'] = 'list2'; $l['view'] = ['style' => 'list', 'listCols' => '2'] + self::VIEW_DEFAULTS; }
			foreach ($l['groups'] as &$g) {
				$tiles = [];
				foreach ($g['tiles'] as $k) {
					$tiles[] = $k;
					if ($k === 'email') $tiles[] = 'notifications';
					if ($id !== 'all') continue;
					// every page of the tile's ▾ / + – not import / sessions (tiles of their own) and not s_debug (the tile itself opens Debug)
					foreach ($sub as $sk => $s) if ($s[3] === $k && !isset($cat[$sk]) && $sk !== 's_debug') $tiles[] = $sk;
				}
				$g['tiles'] = $tiles;
			}
			unset($g);
		} else {
			$l = ['v' => self::VERSION, 'display' => $p['display'], 'size' => $p['size'], 'hidden' => [], 'fav' => $p['fav'] ?? [], 'custom' => [],
				'view' => ($p['view'] ?? []) + ['nav' => $p['display'] === 'tabs' ? 'tabs' : 'groups'] + self::VIEW_DEFAULTS,
				'tabs' => array_map(static fn($t) => ['id' => $t[0], 'title' => t($t[1])], $p['tabs'] ?? []),
				'groups' => array_map(static fn($g) => ['id' => $g[0], 'title' => $g[1] === '' ? '' : t($g[1]), 'tab' => $g[2], 'tiles' => $g[3]], $p['groups'])];
		}
		foreach ($l['groups'] as &$g) $g['tiles'] = array_values(array_filter($g['tiles'], $exists));
		unset($g);
		if ($id !== 'standard' && $id !== 'all') $l['hidden'] = array_values(array_diff(array_keys($cat), self::placed($l)));
		// own tiles
		$l['custom'] = [];
		$custom = $keepOwn && $from ? (array)($from['custom'] ?? []) : [];
		if ($custom) {
			$l['custom'] = $custom;
			$gi = array_search('g_own', array_column($l['groups'], 'id'), true);
			if ($gi === false) {
				array_unshift($l['groups'], ['id' => 'g_own', 'title' => t('home_pf_g_own'), 'tab' => $l['tabs'][0]['id'] ?? '', 'tiles' => []]);
				$gi = 0;
			}
			$hiddenBefore = (array)($from['hidden'] ?? []);
			foreach (array_keys($custom) as $k) {
				if (in_array($k, $hiddenBefore, true)) $l['hidden'][] = $k;
				else $l['groups'][$gi]['tiles'][] = $k;
			}
			foreach ((array)($from['fav'] ?? []) as $k) if (isset($custom[$k]) && !in_array($k, $l['fav'], true)) $l['fav'][] = $k;
		}
		$l['groups'] = array_values(array_filter($l['groups'], static fn($g) => $g['tiles'] || ($g['id'] === 'g_own' && $id === 'blank')));
		return self::clean($l, false)[0];
	}

	/**
	 * The profiles for the page (0.8.385): id => icon, label, hint, layout (without own tiles – the JS adds them), counts of what the
	 * signed-in user sees (tiles his admin role does not allow are not counted).
	 */
	public static function presets(): array
	{
		$out = [];
		foreach (array_keys(self::PRESETS) as $id) {
			$l = (array)self::presetLayout($id);
			$tiles = 0; $groups = 0; $tabs = [];
			foreach ($l['groups'] as $g) {
				$n = count(array_filter($g['tiles'], static fn($k) => self::visible((string)$k, $l)));
				if (!$n) continue;
				$tiles += $n; $groups++; $tabs[$g['tab']] = true;
			}
			$out[$id] = ['icon' => self::PRESETS[$id]['icon'], 'label' => t('home_pf_' . $id), 'hint' => t('home_pf_' . $id . '_hint'), 'layout' => $l,
				'counts' => ['tabs' => $l['display'] !== 'tabs' ? 0 : ($l['tabs'] ? count($tabs) : $groups), 'groups' => $groups, 'tiles' => $tiles, 'hidden' => count($l['hidden'])]];
		}
		return $out;
	}

	// ------------------------------------------------------------------ stored layout

	/** The definition of the current layout (null = the built-in default). */
	public static function stored(): ?array
	{
		$cur = self::current();
		return $cur['def'];
	}

	/**
	 * The layout to show: stored (cleaned) or default; tiles of the catalog that are in no group and not hidden – e.g. new in
	 * a later version – are added to the last group, keys that no longer exist are left out.
	 */
	/** "Favourites only" on the start page (0.8.353): per login – globeadmin in a setting row, users in sus_json, demo visitors in the session. */
	private const FAV_JSON = 'NUNEXT_HOME_FAV_ONLY';
	private const FAV_GA = 'home_fav_only_ga';

	public static function favOnly(): bool
	{
		$u = NuAuth::user();
		if (!$u) return false;
		if (!empty($u['demo'])) return !empty($_SESSION['nu_home_fav_only']);
		if (!empty($u['globeadmin'])) {
			try { return nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::FAV_GA]) === '1'; } catch (Throwable) { return false; }
		}
		return !empty(NuUserJson::get((string)$u['id'], self::FAV_JSON));
	}

	public static function setFavOnly(bool $on): void
	{
		$u = NuAuth::user();
		if (!$u) return;
		if (!empty($u['demo'])) { $_SESSION['nu_home_fav_only'] = $on; return; }
		if (!empty($u['globeadmin'])) {
			NuSchema::ensure();
			$db = nu_db();
			$row = ['setting_value' => $on ? '1' : '0', 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)$u['login'], 0, 50)];
			if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::FAV_GA])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::FAV_GA);
			else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::FAV_GA] + $row);
			return;
		}
		NuUserJson::set((string)$u['id'], self::FAV_JSON, $on);
	}

	public static function layout(): array
	{
		if (self::$for !== null) return self::userOnly(self::clean(self::stored() ?? self::autoLayout(), false)[0]);
		$l = self::clean(self::stored() ?? self::defaultLayout(), false)[0];
		$known = array_merge(array_keys(self::catalog()), array_keys((array)$l['custom']));
		$knownAll = array_merge($known, array_keys(self::subTiles()));     // pinned pages / actions stay; unpinned ones are not added
		$placed = $l['hidden'];
		foreach ($l['groups'] as &$g) {
			$g['tiles'] = array_values(array_filter($g['tiles'], static fn($k) => in_array($k, $knownAll, true)));
			$placed = array_merge($placed, $g['tiles']);
		}
		unset($g);
		$l['hidden'] = array_values(array_filter($l['hidden'], static fn($k) => in_array($k, $knownAll, true)));
		$missing = array_values(array_diff($known, $placed));
		// tiles of a later version next to their neighbour when it is placed (reports after procedures, 0.8.183)
		foreach (['reports' => 'procedures', 'adminroles' => 'sessions', 'notifications' => 'email', 'selftest' => 'todo', 'dbreset' => 'selftest', 'themes' => 'branding', 'cloner' => 'import', 'userfields' => 'permissions'] as $tile => $after) {
			if (!in_array($tile, $missing, true)) continue;
			foreach ($l['groups'] as &$g) {
				$i = array_search($after, $g['tiles'], true);
				if ($i === false) continue;
				array_splice($g['tiles'], $i + 1, 0, [$tile]);
				$missing = array_values(array_diff($missing, [$tile]));
				break;
			}
			unset($g);
		}
		if ($missing) {
			if (!$l['groups']) $l['groups'][] = ['id' => 'g_more', 'title' => t('home_group_more'), 'tab' => $l['tabs'][0]['id'] ?? '', 'tiles' => []];
			$last = count($l['groups']) - 1;
			$l['groups'][$last]['tiles'] = array_merge($l['groups'][$last]['tiles'], $missing);
		}
		return $l;
	}

	/** @return array{0: array, 1: array} [cleaned layout, errors] – $strict: own tiles are checked (save). */
	public static function clean(array $in, bool $strict = true): array
	{
		$errors = [];
		$out = ['v' => self::VERSION,
			'display' => in_array($in['display'] ?? '', self::DISPLAYS, true) ? $in['display'] : 'groups',
			'size' => in_array($in['size'] ?? '', self::SIZES, true) ? $in['size'] : 'large',
			'tabs' => [], 'groups' => [], 'hidden' => [], 'custom' => [], 'fav' => []];
		$key = static fn($k) => is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k) ? $k : null;

		foreach (array_slice(is_array($in['custom'] ?? null) ? $in['custom'] : [], 0, 100, true) as $k => $c) {
			if (($k = $key($k)) === null || !is_array($c) || !str_starts_with($k, 'c_')) continue;
			[$tile, $err] = self::cleanTile($c, $strict);
			if ($err) { $errors[$k] = $err; continue; }
			$out['custom'][$k] = $tile;
		}
		$tabIds = [];
		foreach (array_slice(is_array($in['tabs'] ?? null) ? $in['tabs'] : [], 0, 20) as $i => $tb) {
			if (!is_array($tb)) continue;
			$id = $key($tb['id'] ?? '') ?? 't_' . $i;
			if (isset($tabIds[$id])) continue;
			$tabIds[$id] = true;
			$out['tabs'][] = ['id' => $id, 'title' => mb_substr(trim((string)($tb['title'] ?? '')), 0, 40)];
		}
		$seen = [];
		foreach (array_slice(is_array($in['groups'] ?? null) ? $in['groups'] : [], 0, 30) as $i => $g) {
			if (!is_array($g)) continue;
			$tiles = [];
			foreach (array_slice(is_array($g['tiles'] ?? null) ? $g['tiles'] : [], 0, 200) as $t) {
				if (($t = $key($t)) !== null && !isset($seen[$t])) { $tiles[] = $t; $seen[$t] = true; }
			}
			$title = mb_substr(trim((string)($g['title'] ?? '')), 0, 60);
			$tab = (string)($g['tab'] ?? '');
			$grp = ['id' => $key($g['id'] ?? '') ?? 'g_' . $i, 'title' => $title, 'tab' => isset($tabIds[$tab]) ? $tab : ($out['tabs'][0]['id'] ?? ''), 'tiles' => $tiles];
			if (preg_match('/^#[0-9a-fA-F]{6}$/', (string)($g['color'] ?? ''))) $grp['color'] = strtolower((string)$g['color']);   // own colour of the group (0.8.420)
			if ($cond = self::cleanCond($g['cond'] ?? null)) $grp['cond'] = $cond;   // who sees the group (user start pages, 0.8.425)
			$out['groups'][] = $grp;
		}
		foreach (array_slice(is_array($in['hidden'] ?? null) ? $in['hidden'] : [], 0, 200) as $t) {
			if (($t = $key($t)) !== null && !isset($seen[$t])) { $out['hidden'][] = $t; $seen[$t] = true; }
		}
		// favourites (0.8.353): tiles of the layout marked with ☆ in Customize – "Favourites only" on the start page shows just them
		foreach (array_slice(is_array($in['fav'] ?? null) ? $in['fav'] : [], 0, 200) as $t) {
			if (($t = $key($t)) !== null && isset($seen[$t]) && !in_array($t, $out['fav'], true)) $out['fav'][] = $t;
		}
		$out['custom'] = (object)$out['custom'];
		// view (0.8.420): cleaned, or built from size / display of an older layout; size and display follow it (compatibility)
		$units = $tabIds ?: array_fill_keys(array_column($out['groups'], 'id'), true);   // without tabs every group is a tab of its own
		$out['view'] = is_array($in['view'] ?? null) ? self::cleanView($in['view'], $units, false) : self::legacyView($in);
		[$out['size'], $out['display']] = self::compat($out['view']);
		return [$out, $errors];
	}

	/**
	 * A view (0.8.420): unknown keys are dropped, wrong values become the default ($partial = false: a whole view with tabStyle and
	 * sizes) or are dropped ($partial = true: an entry of sizes or of a personal view). $units: ids a tab style may be set for.
	 */
	public static function cleanView(array $in, array $units, bool $partial): array
	{
		$in = (array)json_decode((string)json_encode($in), true);    // tabStyle / sizes of a cleaned view are objects (JSON {})
		$enums = ['style' => self::VIEW_STYLES, 'listCols' => self::VIEW_LIST_COLS, 'listWidth' => self::VIEW_LIST_WIDTHS, 'desc' => self::VIEW_DESCS,
			'nav' => self::VIEW_NAVS, 'density' => self::VIEW_DENSITIES, 'icon' => self::VIEW_ICONS];
		$out = [];
		foreach (self::VIEW_DEFAULTS as $k => $def) {
			if (!array_key_exists($k, $in)) { if (!$partial) $out[$k] = $def; continue; }
			$v = $in[$k];
			$ok = false;
			if (isset($enums[$k])) { $v = is_scalar($v) ? (string)$v : ''; $ok = in_array($v, $enums[$k], true); }
			elseif ($k === 'lines' || $k === 'delay') { $v = is_numeric($v) ? (int)$v : -1; $ok = in_array($v, $k === 'lines' ? self::VIEW_LINES : self::VIEW_DELAYS, true); }
			elseif (is_bool($v) || in_array($v, [0, 1, '0', '1'], true)) { $v = (bool)$v; $ok = true; }
			if ($ok) $out[$k] = $v;
			elseif (!$partial) $out[$k] = $def;
		}
		if ($partial) return $out;
		$ts = [];
		foreach (array_slice(is_array($in['tabStyle'] ?? null) ? $in['tabStyle'] : [], 0, 20, true) as $id => $st) {
			if (isset($units[(string)$id]) && in_array($st, self::VIEW_STYLES, true)) $ts[(string)$id] = $st;
		}
		$sizes = [];
		foreach (self::VIEW_SIZES as $sz) {
			$e = is_array($in['sizes'][$sz] ?? null) ? self::cleanView($in['sizes'][$sz], [], true) : [];
			if ($e) $sizes[$sz] = $e;
		}
		$out['tabStyle'] = (object)$ts;
		$out['sizes'] = (object)$sizes;
		return $out;
	}

	/** The view of a layout saved before 0.8.420: from its size and display; the compact tooltip keeps its 800 ms. */
	public static function legacyView(array $in): array
	{
		$size = in_array($in['size'] ?? '', self::SIZES, true) ? (string)$in['size'] : 'large';
		$v = match ($size) {
			'compact' => ['style' => 'compact'],
			'list' => ['style' => 'list', 'listCols' => '1', 'listWidth' => 'readable'],
			'listwide' => ['style' => 'list', 'listCols' => '1', 'listWidth' => 'full'],
			'list2' => ['style' => 'list', 'listCols' => '2'],
			default => ['style' => 'large'],
		};
		$v += ['nav' => ($in['display'] ?? '') === 'tabs' ? 'tabs' : 'groups', 'delay' => 800] + self::VIEW_DEFAULTS;
		return self::cleanView($v, [], false);
	}

	/** [size, display] of a view – the fields of older readers (hook variables HOME_SIZE / HOME_DISPLAY, Customise). */
	public static function compat(array $view): array
	{
		$size = match ($view['style'] ?? 'large') {
			'compact', 'launcher' => 'compact',
			'list' => ($view['listCols'] ?? '1') !== '1' ? 'list2' : (($view['listWidth'] ?? '') === 'full' ? 'listwide' : 'list'),
			'table' => 'listwide',
			'panels' => 'list',
			default => 'large',
		};
		return [$size, in_array($view['nav'] ?? '', ['tabs', 'sidebar'], true) ? 'tabs' : 'groups'];
	}

	/** The desktop view a person sees: the layout's view with the personal one over it (screen sizes: assets/js/nuhome.js). */
	public static function effectiveView(array $layout, ?array $overlay): array
	{
		$v = (array)($layout['view'] ?? []) + self::VIEW_DEFAULTS;
		foreach ((array)($overlay['view'] ?? []) as $k => $x) $v[$k] = $x;
		unset($v['tabStyle'], $v['sizes']);
		return $v;
	}

	/** Tile style of a tab on the desktop: personal style > the tab's own style (tabs / sidebar only) > the layout's style. */
	public static function styleFor(array $layout, ?array $overlay, string $tab): string
	{
		if (isset($overlay['view']['style'])) return (string)$overlay['view']['style'];
		$v = (array)($layout['view'] ?? []);
		$ts = (array)($v['tabStyle'] ?? []);
		if (in_array($v['nav'] ?? '', ['tabs', 'sidebar'], true) && isset($ts[$tab])) return (string)$ts[$tab];
		return (string)($v['style'] ?? 'large');
	}

	// ------------------------------------------------------------------ personal view (0.8.420)

	/** @return array<string, array{view: array, sizes: array, at: string}> layout id => personal view; layouts no longer there are left out */
	public static function overlays(): array
	{
		$u = NuAuth::user();
		if (!$u) return [];
		try {
			if (!empty($u['demo'])) $all = $_SESSION['nu_home_view'] ?? [];
			elseif (!empty($u['globeadmin'])) $all = json_decode((string)nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::VIEW_GA]), true);
			else $all = NuUserJson::get((string)$u['id'], self::VIEW_JSON);
		} catch (Throwable) {
			return [];
		}
		if (!is_array($all)) return [];
		$ids = self::layouts();
		$out = [];
		foreach ($all as $id => $e) {
			if (!isset($ids[(string)$id]) || !is_array($e)) continue;
			$e = self::cleanOverlay($e);
			if ($e) $out[(string)$id] = $e;
		}
		return $out;
	}

	public static function overlay(string $layoutId): ?array
	{
		return self::overlays()[$layoutId] ?? null;
	}

	/** Most tiles in a person's own favourites / hidden tiles (user start pages, 0.8.425). */
	public const MINE_MAX = 60;

	/**
	 * An entry of a personal view, cleaned: {view, sizes: {tablet, phone}, at} + on user start pages (0.8.425) fav (own favourites –
	 * replace the layout's) and hide (tiles hidden for this person); [] = nothing left.
	 */
	public static function cleanOverlay(array $in): array
	{
		$in = (array)json_decode((string)json_encode($in), true);
		$view = self::cleanView(is_array($in['view'] ?? null) ? $in['view'] : [], [], true);
		$sizes = [];
		foreach (self::VIEW_SIZES as $sz) {
			$e = is_array($in['sizes'][$sz] ?? null) ? self::cleanView($in['sizes'][$sz], [], true) : [];
			if ($e) $sizes[$sz] = $e;
		}
		$keys = static fn($l) => array_slice(array_values(array_unique(array_filter(is_array($l) ? $l : [], static fn($k) => is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k)))), 0, self::MINE_MAX);
		$fav = array_key_exists('fav', $in) && is_array($in['fav']) ? $keys($in['fav']) : null;
		$hide = $keys($in['hide'] ?? []);
		if (!$view && !$sizes && $fav === null && !$hide) return [];
		$at = (string)($in['at'] ?? '');
		$out = ['view' => $view, 'sizes' => $sizes, 'at' => preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at) ? $at : date('Y-m-d H:i:s')];
		if ($fav !== null) $out['fav'] = $fav;
		if ($hide) $out['hide'] = $hide;
		return $out;
	}

	/**
	 * Own favourites and hidden tiles of a user start page (0.8.425) – the view of the overlay stays. $fav null = the layout's
	 * favourites again. @return bool false = too large
	 */
	public static function setMine(string $layoutId, ?array $fav, array $hide): bool
	{
		$cur = self::overlay($layoutId) ?? [];
		$ov = ['view' => $cur['view'] ?? [], 'sizes' => $cur['sizes'] ?? [], 'hide' => $hide];
		if ($fav !== null) $ov['fav'] = $fav;
		return self::setOverlay($layoutId, $ov, false);
	}

	/** Stores (null: removes) the personal view of a layout – own favourites / hidden tiles stay unless $keepMine is false. @return bool false = too large (VIEW_OVERLAY_BYTES) */
	public static function setOverlay(string $layoutId, ?array $ov, bool $keepMine = true): bool
	{
		$u = NuAuth::user();
		if (!$u || $layoutId === '') return false;
		$all = self::overlays();
		$old = $all[$layoutId] ?? [];
		unset($all[$layoutId]);
		if ($keepMine && $old) {                                  // a change of the view (View panel) keeps the tiles a user chose (0.8.425)
			$ov ??= [];
			foreach (['fav', 'hide'] as $k) if (array_key_exists($k, $old) && !array_key_exists($k, $ov)) $ov[$k] = $old[$k];
		}
		if ($ov !== null) {
			if (strlen((string)json_encode($ov)) > self::VIEW_OVERLAY_BYTES * 2) return false;   // raw payload: refused before cleaning
			$e = self::cleanOverlay(['at' => ''] + $ov);
			if ($e) {
				$e['at'] = date('Y-m-d H:i:s');
				if (strlen((string)json_encode($e)) > self::VIEW_OVERLAY_BYTES) return false;
				$all[$layoutId] = $e;
			}
		}
		uasort($all, static fn($a, $b) => strcmp($b['at'], $a['at']));
		$all = array_slice($all, 0, self::VIEW_OVERLAYS_MAX, true);
		if (!empty($u['demo'])) { $_SESSION['nu_home_view'] = $all; return true; }
		if (!empty($u['globeadmin'])) { self::setSetting(self::VIEW_GA, json_encode((object)$all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)$u['login']); return true; }
		NuUserJson::set((string)$u['id'], self::VIEW_JSON, $all ? $all : null);
		return true;
	}

	/** The view of the current layout (View panel "for everyone" / a personal layout). @return array errors ('' key = refused) */
	public static function saveView(array $view, string $login): array
	{
		$l = self::layout();
		$l['view'] = $view;
		$v = (array)(self::clean($l, false)[0]['view']);
		return self::save($l, $login, 'home_view_saved', ['style' => $v['style'] ?? '', 'nav' => $v['nav'] ?? '']);
	}

	private static function setSetting(string $id, string $value, string $login): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr($login, 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', $id);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $id] + $row);
	}

	/** @return array{0: array, 1: string} [own tile, error text] */
	public static function cleanTile(array $c, bool $strict): array
	{
		$tile = [
			'label' => mb_substr(trim((string)($c['label'] ?? '')), 0, 60), 'hint' => mb_substr(trim((string)($c['hint'] ?? '')), 0, 120),
			'icon' => preg_match('/^bi-[a-z0-9-]{1,40}$/', (string)($c['icon'] ?? '')) ? (string)$c['icon'] : 'bi-star',
			'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($c['color'] ?? '')) ? strtolower((string)$c['color']) : '',
			'kind' => in_array($c['kind'] ?? '', self::KINDS, true) ? (string)$c['kind'] : 'url',
			'target' => mb_substr(trim((string)($c['target'] ?? '')), 0, 500), 'record' => mb_substr(trim((string)($c['record'] ?? '')), 0, 25),
			'run' => ($c['run'] ?? '') === 'hidden' ? 'hidden' : 'window', 'newtab' => !empty($c['newtab']),
		];
		if ($cond = self::cleanCond($c['cond'] ?? null)) $tile['cond'] = $cond;   // who sees the tile (user start pages, 0.8.425)
		if (!$strict) return [$tile, ''];
		if ($tile['label'] === '') return [$tile, t('home_err_label')];
		switch ($tile['kind']) {
			case 'form':
				$f = nu_forms()[$tile['target']] ?? null;
				if (!$f || $f['type'] === 'subform') return [$tile, t('home_err_form')];
				if ($tile['record'] !== '' && !preg_match('/^(-1|[\w\-]{1,25})$/', $tile['record'])) return [$tile, t('home_err_record')];
				break;
			case 'page':
				if (!in_array($tile['target'], self::$for !== null ? self::USER_PAGES : self::PAGES, true)) return [$tile, t('home_err_page')];
				break;
			case 'report':
				if (self::report($tile['target']) === null) return [$tile, t('home_err_report')];
				break;
			case 'procedure':
				if (!nu_db()->value('SELECT COUNT(*) FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$tile['target']])) return [$tile, t('home_err_procedure')];
				break;
			default:
				if (!preg_match('~^(https?://[^\s<>"]+|index\.php\?[^\s<>"]*)$~i', $tile['target'])) return [$tile, t('home_err_url')];
		}
		return [$tile, ''];
	}

	/** @return array errors (own tile key => text); empty = saved */
	public static function save(array $in, string $login, string $event = 'home_layout_saved', ?array $details = null): array
	{
		[$layout, $errors] = self::clean($in);
		if ($errors) return $errors;
		$cur = self::current();
		if (!$cur['editable']) return ['_' => t('err_forbidden')];
		if (self::$for !== null) return self::saveUser(self::userOnly($layout), $login, $event, $details);
		$id = $cur['id'];
		if ($id === 'default') {                               // the built-in layout is never changed: a stored copy takes its place
			$shared = self::canManage();
			$id = self::insertLayout($shared ? t('home_layout_standard') : t('home_layout_mine'), $shared ? 'shared' : 'user', [], $layout);
			if ($shared) NuListView::storeListDefault(self::LIST, $id);
			NuListView::setOwnDefault(['key' => self::LIST], $id);
		} else {
			nu_db()->update(NuListView::TABLE, ['definition' => json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr($login, 0, 50)], 'zzzzsys_nunext_list_view_id', $id);
		}
		self::$current = self::$rows = null;                       // 0.8.652: also the rows – a stored copy of the built-in layout is current from now on
		NuLog::write($event, NuListView::TABLE, $id, $details ?? ['groups' => count($layout['groups']), 'hidden' => count($layout['hidden']), 'own' => count((array)$layout['custom'])]);
		return [];
	}

	/** Saves a user start page (0.8.425): the access level's own row, created on its first save (also when it showed the default). */
	private static function saveUser(array $layout, string $login, string $event, ?array $details): array
	{
		$acc = (string)self::$for['access'];
		$db = nu_db();
		NuSchema::ensure();
		$own = self::userRows()[$acc] ?? null;
		$def = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($own !== null) {
			$id = (string)$own['zzzzsys_nunext_list_view_id'];
			$db->update(NuListView::TABLE, ['definition' => $def, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr($login, 0, 50)], 'zzzzsys_nunext_list_view_id', $id);
		} else {
			$name = $acc === self::ALL_LEVELS ? t('home_u_default') : (string)($db->value('SELECT sal_code FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$acc]) ?? $acc);
			$id = nu_id();
			$db->insert(NuListView::TABLE, ['zzzzsys_nunext_list_view_id' => $id, 'list_key' => self::LIST_USER, 'scope' => 'shared', 'owner' => '', 'name' => mb_substr($name, 0, 100),
				'access_ids' => json_encode([$acc]), 'definition' => $def, 'sort_order' => 0, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr($login, 0, 50)]);
		}
		self::$current = self::$rows = null;
		NuLog::write($event === 'home_layout_saved' ? 'home_user_saved' : $event, NuListView::TABLE, $id, ($details ?? []) + ['access' => $acc, 'groups' => count($layout['groups']), 'own' => count((array)$layout['custom'])]);
		return [];
	}

	/**
	 * Admin roles (0.8.323): which tiles a user with a role sees – the globeadmin sees all. Built-in tile => admin right [right, level]
	 * or a nuBuilder system form (rights of access level + role, NuAcl). Pinned pages follow their tile; own tiles: forms by their
	 * rights, pages by the right of the page, links always.
	 */
	private const TILE_RIGHTS = [
		'users' => ['acl', NuAcl::FORM_USER], 'access' => ['acl', NuAcl::FORM_ACCESS], 'permissions' => ['acl', NuAcl::FORM_PERMISSION],
		'sessions' => ['sessions', 'view'], 'forms' => ['acl', NuAcl::FORM_FORM], 'procedures' => ['acl', NuAcl::FORM_PROCEDURE],
		'reports' => ['acl', NuAcl::FORM_REPORT], 'prompts' => ['acl', NuAcl::FORM_PROMPT], 'apiclients' => ['apiclients', 'view'], 'webhooks' => ['webhooks', 'view'],
		'email' => ['email', 'view'], 'debug' => ['acl', NuAcl::FORM_DEBUG], 'update' => ['update', 'view'], 'settings' => ['settings', 'view'],
		'pma' => ['dbtool', 'change'], 'tfm' => ['filemanager', 'change'], 'vendor' => ['status', 'view'], 'securitycheck' => ['securitycheck', 'view'],
		'configfile' => ['configfile', 'change'], 'globalcode' => ['globalcode', 'view'], 'setupwizard' => ['setupwizard', 'change'], 'branding' => ['branding', 'view'], 'themes' => ['branding', 'view'],
		'dashboards' => ['dashboards', 'view'], 'import' => ['import', 'change'], 'appcloner' => ['appcloner', 'change'], 'cloner' => ['forms', 'change'], 'sample' => ['sample', 'view'],
		'compat' => ['compat', 'view'], 'todo' => ['globeadmin', ''], 'selftest' => ['globeadmin', ''], 'dbreset' => ['globeadmin', ''], 'adminroles' => ['adminroles', 'change'], 'tutorial' => ['any', ''], 'userfields' => ['userfields', 'view'],
		's_audit' => ['acl', NuAcl::FORM_AUDIT], 'notifications' => ['notifications', 'view'], 's_user_home' => ['homelayout', 'change'],
	];
	/** Pages an own tile (kind page) may open => the tile whose right decides. */
	private const PAGE_TILE = ['users' => 'users', 'access' => 'access', 'permissions' => 'permissions', 'sessions' => 'sessions', 'forms' => 'forms',
		'procedures' => 'procedures', 'reports' => 'reports', 'sqlbuilder' => 'reports', 'prompts' => 'prompts', 'apiclients' => 'apiclients', 'webhooks' => 'webhooks',
		'apiplayground' => 'apiclients', 'email' => 'email', 'emailtemplates' => 'email', 'debug' => 'debug', 'log' => 'debug', 'audit' => 's_audit', 'notifications' => 'notifications', 'update' => 'update',
		'settings' => 'settings', 'branding' => 'branding', 'themes' => 'themes', 'dashboards' => 'dashboards', 'vendor' => 'vendor', 'sample' => 'sample', 'import' => 'import',
		'cloner' => 'cloner', 'appcloner' => 'appcloner', 'files' => 'forms', 'items' => 'forms', 'formats' => 'forms', 'translations' => 'forms',
		'securitycheck' => 'securitycheck', 'setupwizard' => 'setupwizard', 'configfile' => 'configfile', 'globalcode' => 'globalcode', 'compat' => 'compat', 'adminroles' => 'adminroles', 'userfields' => 'userfields',
		'tutorial' => 'tutorial'];

	/** May the signed-in user see this tile? */
	public static function visible(string $key, array $layout): bool
	{
		$u = NuAuth::user();
		if (!$u) return false;
		if (self::$for !== null) return self::visibleUser($key, $layout);
		if ($u['globeadmin']) return true;
		if (isset(self::subTiles()[$key]) && !isset(self::TILE_RIGHTS[$key])) $key = self::subTiles()[$key][3];   // pinned page: as its tile
		if (isset(self::TILE_RIGHTS[$key])) {
			[$r, $lvl] = self::TILE_RIGHTS[$key];
			if ($r === 'globeadmin') return false;
			if ($r === 'any') return NuAdmin::any();
			return $r === 'acl' ? NuAcl::can($lvl) : NuAdmin::can($r, $lvl);
		}
		if ($key === 'notes') return NuAcl::can('nunotes');
		if ($key === 'userhome') return NuAcl::can(NuUserHome::FORM);
		$c = (array)(((array)$layout['custom'])[$key] ?? []);
		if (!$c) return false;
		if (($c['kind'] ?? '') === 'form') return NuAcl::can((string)$c['target']);
		if (($c['kind'] ?? '') === 'page') return isset(self::PAGE_TILE[$c['target']]) ? self::visible(self::PAGE_TILE[$c['target']], $layout) : in_array($c['target'], ['help', 'security', 'password'], true);
		return true;
	}

	/** Keys placed in a group (not hidden). */
	public static function placed(array $layout): array
	{
		return array_merge([], ...array_map(static fn($g) => (array)$g['tiles'], (array)$layout['groups']));
	}

	/**
	 * Pins a page / action of a tile's menu as a tile (0.8.321) – into the group of its tile, after it and the ones pinned before – or
	 * takes it off the start page again when it is pinned already. @return bool|null pinned now (null = unknown key / not saved)
	 */
	public static function togglePin(string $key, string $login): ?bool
	{
		$sub = self::subTiles()[$key] ?? null;
		if (!$sub) return null;
		$l = self::layout();
		$l['hidden'] = array_values(array_diff($l['hidden'], [$key]));
		if (in_array($key, self::placed($l), true)) {
			foreach ($l['groups'] as &$g) $g['tiles'] = array_values(array_diff($g['tiles'], [$key]));
			unset($g);
			$pinned = false;
			if (isset(self::catalog()[$key])) $l['hidden'][] = $key;   // a tile of its own (CSV import, logged-in users): hidden, not re-added
		} else {
			$parent = $sub[3];
			$gi = null; $pos = 0;
			foreach ($l['groups'] as $i => $g) {
				$at = array_search($parent, $g['tiles'], true);
				if ($at === false) continue;
				$gi = $i; $pos = $at + 1;
				while (isset($g['tiles'][$pos]) && (self::subTiles()[$g['tiles'][$pos]][3] ?? '') === $parent) $pos++;   // after its pinned siblings
				break;
			}
			if ($gi === null) {
				if (!$l['groups']) $l['groups'][] = ['id' => 'g_more', 'title' => t('home_group_more'), 'tab' => $l['tabs'][0]['id'] ?? '', 'tiles' => []];
				$gi = count($l['groups']) - 1;
				$pos = count($l['groups'][$gi]['tiles']);
			}
			array_splice($l['groups'][$gi]['tiles'], $pos, 0, [$key]);
			$pinned = true;
		}
		return self::save($l, $login) ? null : $pinned;
	}

	// ------------------------------------------------------------------ saved layouts (0.8.333)
	// Stored like list views (zzzzsys_nunext_list_view, list_key 'home'): shared (by "Start page layout" admins, for everyone or for chosen
	// admin roles – access_ids holds role ids here) and personal ones. The one shown is the user's own choice, else the default for
	// everyone, else the built-in default. The single layout of earlier versions became the shared layout "Standard" (step 0010).

	public const LIST = 'home';
	private static ?array $current = null;

	/** Shared layouts, the default for everyone: the globeadmin or the admin right "Start page layout". */
	public static function canManage(): bool
	{
		return NuAdmin::can('homelayout', 'change');
	}

	private static function me(): string
	{
		$u = NuAuth::user();
		return !empty($u['globeadmin']) ? 'globeadmin' : (string)($u['id'] ?? '');
	}

	/** @return array<string, array{id: string, name: string, scope: string, roles: array, def: ?array, editable: bool, mine: bool}> */
	public static function layouts(): array
	{
		if (self::$for !== null) return self::userLayouts();
		$manage = self::canManage();
		$out = ['default' => ['id' => 'default', 'name' => t('home_layout_builtin'), 'scope' => 'builtin', 'roles' => [], 'def' => null, 'editable' => true, 'mine' => false]];
		$db = nu_db();
		try {
			if (!$db->tableExists(NuListView::TABLE)) return $out;
			$rows = $db->all('SELECT * FROM ' . NuListView::TABLE . ' WHERE list_key = ? ORDER BY scope, sort_order, name', [self::LIST]);
		} catch (Throwable) {
			return $out;
		}
		$role = (string)(NuAdmin::role()['id'] ?? '');
		$me = self::me();
		foreach ($rows as $r) {
			$roles = json_decode((string)$r['access_ids'], true);
			$roles = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
			$shared = $r['scope'] === 'shared';
			if ($shared && !$manage && $roles && !in_array($role, $roles, true)) continue;
			if (!$shared && (string)$r['owner'] !== $me) continue;
			$def = json_decode((string)$r['definition'], true);
			$out[(string)$r['zzzzsys_nunext_list_view_id']] = ['id' => (string)$r['zzzzsys_nunext_list_view_id'], 'name' => (string)$r['name'], 'scope' => (string)$r['scope'],
				'roles' => $roles, 'def' => is_array($def) ? $def : null, 'editable' => $shared ? $manage : true, 'mine' => !$shared];
		}
		// the built-in default: listed when nothing replaces it; editable only by layout admins (it becomes the shared "Standard")
		$out['default']['editable'] = $manage;
		if (count($out) > 1 && NuListView::listDefault(self::LIST) !== 'default') unset($out['default']);
		return $out;
	}

	/** The layout shown to the signed-in user. */
	public static function current(): array
	{
		if (self::$current !== null) return self::$current;
		$all = self::layouts();
		if (self::$for !== null) return self::$current = reset($all);
		foreach ([NuListView::myDefault(self::LIST), NuListView::listDefault(self::LIST), 'default'] as $id) {
			if ($id !== '' && isset($all[$id])) return self::$current = $all[$id];
		}
		return self::$current = reset($all) ?: ['id' => 'default', 'name' => '', 'scope' => 'builtin', 'roles' => [], 'def' => null, 'editable' => false, 'mine' => false];
	}

	private static function insertLayout(string $name, string $scope, array $roles, array $def): string
	{
		$db = nu_db();
		NuSchema::ensure();
		$id = nu_id();
		$db->insert(NuListView::TABLE, ['zzzzsys_nunext_list_view_id' => $id, 'list_key' => self::LIST, 'scope' => $scope, 'owner' => $scope === 'shared' ? '' : self::me(),
			'name' => mb_substr($name, 0, 100), 'access_ids' => json_encode(array_values($roles)), 'definition' => json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'sort_order' => (int)$db->value('SELECT COUNT(*) FROM ' . NuListView::TABLE . ' WHERE list_key = ?', [self::LIST]) + 1,
			'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)]);
		return $id;
	}

	private static function roleIds(array $in): array
	{
		if (!NuAdmin::ready()) return [];
		$known = array_column(NuAdmin::roles(), 'id');
		return array_values(array_unique(array_filter(array_map('strval', $in), static fn($r) => in_array($r, $known, true))));
	}

	/** Switch: the chosen layout becomes the user's own layout. */
	public static function select(string $id): bool
	{
		if (!isset(self::layouts()[$id])) return false;
		NuListView::setOwnDefault(['key' => self::LIST], $id);
		self::$current = null;
		return true;
	}

	/** Save as a new layout (name, personal / shared, admin roles) and switch to it – a copy of the current one or, with $preset
	 *  ("Based on", 0.8.385), a profile (own tiles of the current layout along with $keepOwn). @return string error ('' = done) */
	public static function saveAs(string $name, string $scope, array $roles, string $preset = '', bool $keepOwn = false): string
	{
		$name = trim($name);
		if ($name === '') return t('lv_err_name');
		$shared = $scope === 'shared';
		if ($shared && !self::canManage()) return t('err_forbidden');
		$def = $preset === '' ? self::layout() : self::presetLayout($preset, self::layout(), $keepOwn);
		if ($def === null) return t('err_not_found');
		$id = self::insertLayout($name, $shared ? 'shared' : 'user', $shared ? self::roleIds($roles) : [], $def);
		NuListView::setOwnDefault(['key' => self::LIST], $id);
		if ($shared || $preset !== '') NuLog::write('home_layout_saved', NuListView::TABLE, $id, ['name' => $name, 'shared' => $shared] + ($preset !== '' ? ['preset' => $preset] : []));
		self::$current = null;
		return '';
	}

	/** Name and visibility of the current layout. */
	public static function meta(string $name, string $scope, array $roles): string
	{
		$cur = self::current();
		if ($cur['id'] === 'default' || !$cur['editable']) return t('err_forbidden');
		$name = trim($name);
		if ($name === '') return t('lv_err_name');
		$shared = $scope === 'shared';
		if ($shared !== ($cur['scope'] === 'shared') && !self::canManage()) return t('err_forbidden');
		nu_db()->update(NuListView::TABLE, ['name' => mb_substr($name, 0, 100), 'scope' => $shared ? 'shared' : 'user', 'owner' => $shared ? '' : self::me(),
			'access_ids' => json_encode($shared ? self::roleIds($roles) : []), 'updated_at' => date('Y-m-d H:i:s')], 'zzzzsys_nunext_list_view_id', $cur['id']);
		if (!$shared && NuListView::listDefault(self::LIST) === $cur['id']) NuListView::storeListDefault(self::LIST, 'default');
		self::$current = null;
		return '';
	}

	public static function deleteCurrent(): string
	{
		$cur = self::current();
		if ($cur['id'] === 'default' || !$cur['editable']) return t('err_forbidden');
		if ($cur['scope'] === 'shared' && NuListView::listDefault(self::LIST) === $cur['id']) return t('home_layout_err_default');
		nu_db()->run('DELETE FROM ' . NuListView::TABLE . ' WHERE zzzzsys_nunext_list_view_id = ?', [$cur['id']]);
		NuListView::setOwnDefault(['key' => self::LIST], '');
		NuLog::write('home_layout_deleted', NuListView::TABLE, $cur['id'], ['name' => $cur['name']]);
		self::$current = null;
		return '';
	}

	/** The current (shared) layout becomes the default for everyone. */
	public static function makeDefault(): string
	{
		$cur = self::current();
		if (!self::canManage() || $cur['scope'] === 'user') return t('err_forbidden');
		NuListView::storeListDefault(self::LIST, $cur['id']);
		NuLog::write('home_layout_default', NuListView::TABLE, $cur['id'], ['name' => $cur['name']]);
		return '';
	}

	/** Database step 0010: the single layout of earlier versions becomes the shared layout "Standard", default for everyone. */
	public static function migrateSingle(): void
	{
		$db = nu_db();
		NuSchema::create();
		if (!$db->tableExists('zzzzsys_nunext_setting')) return;
		$json = $db->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
		$d = is_string($json) ? json_decode($json, true) : null;
		if (is_array($d) && !(int)$db->value('SELECT COUNT(*) FROM ' . NuListView::TABLE . ' WHERE list_key = ?', [self::LIST])) {
			$id = nu_id();
			$db->insert(NuListView::TABLE, ['zzzzsys_nunext_list_view_id' => $id, 'list_key' => self::LIST, 'scope' => 'shared', 'owner' => '', 'name' => 'Standard',
				'access_ids' => '[]', 'definition' => (string)$json, 'sort_order' => 1, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => 'update']);
			NuListView::storeListDefault(self::LIST, $id);
		}
		$db->run('DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
	}

	// ------------------------------------------------------------------ rendering helpers

	/**
	 * Tabs to show (display tabs): [id, title, groups] – defined tabs with their groups in group order, empty tabs left out;
	 * without defined tabs every group is a tab. $groups: the groups to draw (with tiles).
	 */
	public static function tabsOf(array $layout, array $groups): array
	{
		if (!$layout['tabs']) return array_map(static fn($g) => ['id' => $g['id'], 'title' => $g['title'], 'groups' => [$g + ['plain' => true]]], $groups);
		$out = [];
		foreach ($layout['tabs'] as $tb) {
			$in = array_values(array_filter($groups, static fn($g) => $g['tab'] === $tb['id']));
			if ($in) $out[] = ['id' => $tb['id'], 'title' => $tb['title'], 'groups' => $in];
		}
		return $out;
	}

	/** A tile ready to draw: [href | null, post app | null, icon, label, hint, color, newtab, procedure id | null, run mode]. */
	public static function resolve(string $key, array $layout): ?array
	{
		$cat = self::catalog();
		if (isset($cat[$key])) {
			[$route, $icon, $label, $hint] = $cat[$key];
			$badge = $cat[$key][4] ?? null;
			$post = is_array($route) && isset($route['post']) ? $route['post'] : null;
			$href = $post ? null : (is_array($route) ? nu_url($route[0], $route[1]) : nu_url($route));
			// File Manager (0.8.516): in a new tab, like the database tools – it is a work area of its own
			return ['href' => $href, 'post' => $post, 'icon' => $icon, 'label' => $label, 'hint' => $hint, 'color' => '', 'newtab' => (bool)$post || $key === 'tfm', 'proc' => null, 'run' => '',
				'action' => self::actions()[$key] ?? null, 'badge' => $badge, 'kind' => $post ? 'post' : 'page'];
		}
		$sub = self::subTiles()[$key] ?? null;
		if ($sub) {                                         // a pinned page / action of a tile's menu (0.8.321)
			$parent = self::catalog()[$sub[3]][2] ?? '';
			return ['href' => $sub[0], 'post' => null, 'icon' => $sub[1], 'label' => $sub[2], 'hint' => $parent, 'color' => '', 'newtab' => false, 'proc' => null, 'run' => '',
				'action' => null, 'badge' => null, 'kind' => 'page'];
		}
		$c = ((array)$layout['custom'])[$key] ?? null;
		if (!$c) return null;
		$c = (array)$c;
		$href = match ($c['kind']) {
			'form' => isset(nu_forms()[$c['target']]) ? nu_url('form', ['f' => $c['target'], 'r' => $c['record'] !== '' ? $c['record'] : null]) : null,
			'page' => in_array($c['target'], self::PAGES, true) ? nu_url($c['target']) : null,
			'report' => ($r = self::report((string)$c['target'])) !== null ? nu_url('report', ['c' => $r['code']]) : null,
			'procedure' => null,
			default => preg_match('~^(https?://|index\.php\?)~i', (string)$c['target']) ? (string)$c['target'] : null,
		};
		if ($href === null && $c['kind'] !== 'procedure') return null;
		return ['href' => $href, 'post' => null, 'icon' => $c['icon'], 'label' => $c['label'], 'hint' => $c['hint'], 'color' => $c['color'],
			'newtab' => (bool)$c['newtab'] || ($c['kind'] === 'form' && NuPage::opensInTab((string)$c['target'])), 'proc' => $c['kind'] === 'procedure' ? (string)$c['target'] : null, 'run' => $c['run'], 'action' => null, 'badge' => null, 'kind' => (string)$c['kind'],
			'cond' => (array)($c['cond'] ?? [])];
	}

	// ------------------------------------------------------------------ user start pages (0.8.425)

	/** ['access' => access id | '*', 'preview' => true on the editor page (rights of that access level, conditions not applied)] */
	private static ?array $for = null;

	/** Switches NuHome to the start page of an access level ('*' = the default for all access levels). */
	public static function forLevel(string $access, bool $preview): void
	{
		self::$for = ['access' => $access, 'preview' => $preview];
		self::$current = null;
	}

	/** null = the admin tile page; else ['access', 'preview']. */
	public static function target(): ?array
	{
		return self::$for;
	}

	/** Rows of the user start pages: access id => row, '*' = the default (the first row naming an access level wins). */
	private static ?array $rows = null;

	private static function userRows(): array
	{
		if (self::$rows !== null) return self::$rows;
		$rows = [];
		$db = nu_db();
		try {
			if (!$db->tableExists(NuListView::TABLE)) return self::$rows = $rows;
			foreach ($db->all('SELECT * FROM ' . NuListView::TABLE . ' WHERE list_key = ? ORDER BY sort_order, name', [self::LIST_USER]) as $r) {
				$ids = json_decode((string)$r['access_ids'], true);
				foreach (is_array($ids) ? $ids : [] as $a) if (is_string($a) && !isset($rows[$a])) $rows[$a] = $r;
			}
		} catch (Throwable) { /* no table yet */ }
		return self::$rows = $rows;
	}

	/**
	 * The one layout a user start page shows: the access level's own (source level), the default for all access levels (default),
	 * or the automatic one (auto). editable = the editor page with the right "Start page layout".
	 */
	private static function userLayouts(): array
	{
		$acc = (string)self::$for['access'];
		$rows = self::userRows();
		$editable = self::$for['preview'] && self::canManage();
		$row = $rows[$acc] ?? ($acc !== self::ALL_LEVELS ? ($rows[self::ALL_LEVELS] ?? null) : null);
		if ($row === null) {
			return ['auto' => ['id' => 'auto', 'name' => t('home_u_auto'), 'scope' => 'level', 'roles' => [], 'def' => null, 'editable' => $editable, 'mine' => false, 'own' => false, 'source' => 'auto']];
		}
		$def = json_decode((string)$row['definition'], true);
		$own = isset($rows[$acc]);
		$id = (string)$row['zzzzsys_nunext_list_view_id'];
		return [$id => ['id' => $id, 'name' => (string)$row['name'], 'scope' => 'level', 'roles' => [], 'def' => is_array($def) ? $def : null, 'editable' => $editable,
			'mine' => false, 'own' => $own, 'source' => $own && $acc !== self::ALL_LEVELS ? 'level' : 'default']];
	}

	/** Only own tiles on a user start page (keys of the catalogue, pinned pages and stale keys are dropped). */
	private static function userOnly(array $l): array
	{
		$own = array_keys((array)$l['custom']);
		$keep = static fn(array $keys) => array_values(array_filter($keys, static fn($k) => in_array($k, $own, true)));
		foreach ($l['groups'] as &$g) $g['tiles'] = $keep($g['tiles']);
		unset($g);
		$l['hidden'] = $keep($l['hidden']);
		$placed = array_merge($l['hidden'], ...array_map(static fn($g) => $g['tiles'], $l['groups']));
		$missing = array_values(array_diff($own, $placed));       // own tiles in no group: hidden (never lost)
		$l['hidden'] = array_merge($l['hidden'], $missing);
		$l['fav'] = $keep((array)$l['fav']);
		return $l;
	}

	/**
	 * The automatic start page of an access level (F1): its forms (no sub-forms, no nuBuilder system forms; the home form first) and
	 * the reports it may run – own tiles with stable keys, so favourites and hidden tiles stay. '*': one empty group.
	 */
	public static function autoLayout(?string $access = null): array
	{
		$access ??= (string)(self::$for['access'] ?? '');
		$l = ['v' => self::VERSION, 'display' => 'groups', 'size' => 'large', 'hidden' => [], 'fav' => [], 'custom' => [], 'tabs' => [],
			'view' => ['style' => 'cards'] + self::VIEW_DEFAULTS, 'groups' => []];
		if ($access === self::ALL_LEVELS || $access === '') {
			$l['groups'][] = ['id' => 'g_main', 'title' => '', 'tab' => '', 'tiles' => []];
			return $l;
		}
		$db = nu_db();
		$forms = nu_forms();
		$home = (string)($db->value('SELECT sal_zzzzsys_form_id FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$access]) ?? '');
		$fTiles = [];
		// the signed-in user's own page (not the editor's preview): also the forms / reports of his additional access levels (0.8.585)
		$u = NuAuth::user();
		$lvls = empty(self::$for['preview']) && $u && (string)($u['access_id'] ?? '') === $access ? NuUserAccess::levels($u) : [$access];
		if (!$lvls) $lvls = [$access];
		$qm = implode(', ', array_fill(0, count($lvls), '?'));
		foreach ($db->all("SELECT DISTINCT slf_zzzzsys_form_id FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id IN ($qm)", $lvls) as $r) {
			$fid = (string)$r['slf_zzzzsys_form_id'];
			$f = $forms[$fid] ?? null;
			$uh = $fid === NuUserHome::FORM;                    // nuBuilder Forte's User Home is shown (0.8.602), other system forms not
			if (!$f || $f['type'] === 'subform' || (!$uh && (!empty($f['system']) || str_starts_with($fid, 'nu')))) continue;
			$fTiles[$fid] = ['label' => mb_substr($f['description'] !== '' ? $f['description'] : $f['code'], 0, 60), 'hint' => $fid === $home ? t('home_form') : '',
				'icon' => $f['type'] === 'launch' || $f['type'] === 'page' ? 'bi-window' : 'bi-table', 'color' => '', 'kind' => 'form', 'target' => $fid, 'record' => '', 'run' => 'window', 'newtab' => false];
		}
		// home form nuuserhome without a row in zzzzsys_access_form: the level may open it all the same (NuAcl::rights, 0.8.602)
		if ($home === NuUserHome::FORM && !isset($fTiles[$home]) && isset($forms[$home])) {
			$fTiles[$home] = ['label' => mb_substr($forms[$home]['description'] !== '' ? $forms[$home]['description'] : $forms[$home]['code'], 0, 60), 'hint' => t('home_form'),
				'icon' => 'bi-house-door', 'color' => '', 'kind' => 'form', 'target' => $home, 'record' => '', 'run' => 'window', 'newtab' => false];
		}
		uksort($fTiles, static fn($a, $b) => ($b === $home) <=> ($a === $home) ?: strcasecmp($fTiles[$a]['label'], $fTiles[$b]['label']));
		$rTiles = [];
		try {
			if ($db->tableExists(NuReport::TABLE)) {
				foreach ($db->all('SELECT r.zzzzsys_report_id, r.sre_code, r.sre_description FROM zzzzsys_report r JOIN zzzzsys_access_report a ON a.sre_zzzzsys_report_id = r.zzzzsys_report_id
					WHERE a.sre_zzzzsys_access_id IN (' . $qm . ') ORDER BY r.sre_description, r.sre_code', $lvls) as $r) {
					$rTiles[(string)$r['zzzzsys_report_id']] = ['label' => mb_substr((string)($r['sre_description'] ?: $r['sre_code']), 0, 60), 'hint' => '', 'icon' => 'bi-file-earmark-richtext',
						'color' => '', 'kind' => 'report', 'target' => (string)$r['zzzzsys_report_id'], 'record' => '', 'run' => 'window', 'newtab' => false];
				}
			}
		} catch (Throwable) { /* no reports */ }
		$key = static fn(string $p, string $id) => 'c_' . $p . substr(hash('sha256', $id), 0, 12);
		foreach ([['g_forms', t('nav_forms'), 'f', $fTiles], ['g_reports', t('rpt_title_list'), 'r', $rTiles]] as [$gid, $title, $p, $tiles]) {
			if (!$tiles) continue;
			$g = ['id' => $gid, 'title' => $title, 'tab' => '', 'tiles' => []];
			foreach ($tiles as $id => $tl) { $k = $key($p, (string)$id); $l['custom'][$k] = $tl; $g['tiles'][] = $k; }
			$l['groups'][] = $g;
		}
		if (!$l['groups']) $l['groups'][] = ['id' => 'g_main', 'title' => '', 'tab' => '', 'tiles' => []];
		return $l;
	}

	/**
	 * New form wizard (0.8.602): a tile for the form on the saved user start pages these access levels show – their own layout, else
	 * the default one ('*'); levels without a saved layout get it on the automatic page by their rights. The key is the one
	 * autoLayout() uses, so the tile is not doubled. @return int layouts changed
	 */
	public static function addFormTile(array $levels, string $formId, string $label, string $login): int
	{
		$rows = self::userRows();
		$ids = [];
		foreach ($levels as $acc) {
			$row = $rows[(string)$acc] ?? ($rows[self::ALL_LEVELS] ?? null);
			if ($row) $ids[(string)$row['zzzzsys_nunext_list_view_id']] = $row;
		}
		$key = 'c_f' . substr(hash('sha256', $formId), 0, 12);
		$n = 0;
		foreach ($ids as $id => $row) {
			$def = json_decode((string)$row['definition'], true);
			if (!is_array($def)) continue;
			$custom = (array)($def['custom'] ?? []);
			if (isset($custom[$key])) continue;
			$custom[$key] = ['label' => mb_substr($label, 0, 60), 'hint' => '', 'icon' => 'bi-table', 'color' => '', 'kind' => 'form', 'target' => $formId,
				'record' => '', 'run' => 'window', 'newtab' => false];
			$def['custom'] = $custom;
			$def['groups'] = is_array($def['groups'] ?? null) && $def['groups'] ? $def['groups'] : [['id' => 'g_main', 'title' => '', 'tab' => '', 'tiles' => []]];
			$def['groups'][0]['tiles'][] = $key;
			nu_db()->update(NuListView::TABLE, ['definition' => json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s'),
				'updated_by' => mb_substr($login, 0, 50)], 'zzzzsys_nunext_list_view_id', $id);
			NuLog::write('home_user_saved', NuListView::TABLE, $id, ['tile' => $label, 'form' => $formId]);
			$n++;
		}
		self::$rows = self::$current = null;
		return $n;
	}

	/** May the user see a tile of a user start page? Own tiles only; the editor (preview) sees by the rights of the access level. */
	private static function visibleUser(string $key, array $layout): bool
	{
		$c = (array)(((array)$layout['custom'])[$key] ?? []);
		if (!$c) return false;
		if (!self::condOk((array)($c['cond'] ?? []))) return false;
		$acc = (string)self::$for['access'];
		$preview = (bool)self::$for['preview'];
		$target = (string)($c['target'] ?? '');
		$db = nu_db();
		switch ($c['kind'] ?? '') {
			case 'form':
				if (!isset(nu_forms()[$target])) return false;
				if (!$preview) return NuAcl::can($target);
				return $acc === self::ALL_LEVELS || NuAcl::levelRights($acc, $target)['view'];
			case 'report':
				$r = self::report($target);
				if ($r === null) return false;
				if (!$preview) return NuReport::canRun($r['row']);
				return $acc === self::ALL_LEVELS || (bool)$db->value('SELECT COUNT(*) FROM zzzzsys_access_report WHERE sre_zzzzsys_access_id = ? AND sre_zzzzsys_report_id = ?', [$acc, $target]);
			case 'procedure':
				$p = $db->one('SELECT zzzzsys_php_id, sph_global FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$target]);
				if (!$p) return false;
				$u = NuAuth::user();
				if ((string)$p['sph_global'] === '1' || (!$preview && (!empty($u['globeadmin']) || NuAdmin::can('procedures', 'change')))) return true;
				if ($preview && $acc === self::ALL_LEVELS) return true;
				[$in, $lv] = $preview ? ['slp_zzzzsys_access_id = ?', [$acc]] : NuUserAccess::inSql('slp_zzzzsys_access_id', $u);   // + additional levels (0.8.585)
				return $db->tableExists('zzzzsys_access_php') && (bool)$db->value("SELECT COUNT(*) FROM zzzzsys_access_php WHERE $in AND slp_zzzzsys_php_id = ?", [...$lv, $target]);
			case 'page':
				return in_array($target, self::USER_PAGES, true) && !($target === 'security' && NuImpersonate::active());
			default:
				return true;
		}
	}

	/**
	 * Conditions of a tile or group (0.8.425): every kind given must match – permission items (any of them), team, department, position
	 * (any value, case-insensitive). The editor page shows everything (conditions are marked there); the globeadmin always passes.
	 */
	public static function condOk(array $cond): bool
	{
		if (!$cond || self::$for === null || self::$for['preview']) return true;
		$f = self::facts();
		if ($f === null) return true;                            // the globeadmin
		foreach (self::COND_KINDS as $k) {
			$want = array_map(static fn($x) => mb_strtolower(trim((string)$x)), (array)($cond[$k] ?? []));
			if (!$want) continue;
			$have = $k === 'permission' ? $f['permission'] : [$f[$k]];
			if (!array_intersect($want, $have)) return false;
		}
		foreach ((array)($cond['fields'] ?? []) as $code => $vals) {   // custom user fields (0.8.454): the value is one of the listed ones
			$want = array_map(static fn($x) => mb_strtolower(trim((string)$x)), (array)$vals);
			if ($want && !in_array(mb_strtolower(trim((string)($f['fields'][$code] ?? ''))), $want, true)) return false;
		}
		return true;
	}

	/** Team, department, position and permission items of the signed-in user (lower case); null for the globeadmin. */
	private static function facts(): ?array
	{
		static $f = false;
		if ($f !== false) return $f;
		$u = NuAuth::user();
		if (!$u || !empty($u['globeadmin'])) return $f = null;
		$row = nu_db()->one('SELECT sus_team, sus_department, sus_position FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [(string)$u['id']]) ?? [];
		$low = static fn($v) => mb_strtolower(trim((string)$v));
		return $f = ['team' => $low($row['sus_team'] ?? ''), 'department' => $low($row['sus_department'] ?? ''), 'position' => $low($row['sus_position'] ?? ''),
			'permission' => array_map($low, NuPermission::idents((string)$u['id'])), 'fields' => NuUserFields::mine()];
	}

	/** Conditions as stored: kind => list of values (max 20, 60 characters each); a string is split at commas. [] = none. */
	public static function cleanCond(mixed $in): array
	{
		if (!is_array($in)) return [];
		$out = [];
		foreach (self::COND_KINDS as $k) {
			$v = $in[$k] ?? [];
			if (is_string($v)) $v = explode(',', $v);
			if (!is_array($v)) continue;
			$list = [];
			foreach ($v as $x) {
				$x = mb_substr(trim(is_scalar($x) ? (string)$x : ''), 0, 60);
				if ($x !== '' && !in_array($x, $list, true)) $list[] = $x;
			}
			if ($list) $out[$k] = array_slice($list, 0, 20);
		}
		$fields = [];                                            // custom user fields (0.8.454): {code: [values]}
		foreach (is_array($in['fields'] ?? null) ? $in['fields'] : [] as $code => $v) {
			$code = strtolower((string)$code);
			if (!isset(NuUserFields::all()[$code])) continue;
			if (is_string($v)) $v = explode(',', $v);
			$list = [];
			foreach (is_array($v) ? $v : [] as $x) {
				$x = mb_substr(trim(is_scalar($x) ? (string)$x : ''), 0, 60);
				if ($x !== '' && !in_array($x, $list, true)) $list[] = $x;
			}
			if ($list) $fields[$code] = array_slice($list, 0, 20);
		}
		if ($fields) $out['fields'] = $fields;
		return $out;
	}

	/** Conditions in words for the editor's marker: "Team: Sales, Support · Permission item: TILE_BUDGET". */
	public static function condText(array $cond): string
	{
		$out = [];
		foreach (self::COND_KINDS as $k) if (!empty($cond[$k])) $out[] = t('home_u_cond_' . $k) . ': ' . implode(', ', array_map('strval', (array)$cond[$k]));
		$all = NuUserFields::all();
		foreach ((array)($cond['fields'] ?? []) as $code => $vals) $out[] = (isset($all[$code]) ? NuUserFields::label($all[$code]) : $code) . ': ' . implode(', ', array_map('strval', (array)$vals));
		return t('home_u_cond_only') . ' ' . implode(' · ', $out);
	}

	/** Values offered in the condition fields of the tile editor: permission items, and the teams / departments / positions in use. */
	public static function condOptions(): array
	{
		$db = nu_db();
		$out = ['permission' => [], 'team' => [], 'department' => [], 'position' => []];
		try {
			if ($db->tableExists('zzzzsys_permission_item')) $out['permission'] = array_map('strval', array_column($db->all('SELECT pme_ident FROM zzzzsys_permission_item WHERE pme_ident IS NOT NULL ORDER BY pme_ident'), 'pme_ident'));
			foreach (['team' => 'sus_team', 'department' => 'sus_department', 'position' => 'sus_position'] as $k => $col) {
				$out[$k] = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), array_column($db->all("SELECT DISTINCT $col AS v FROM zzzzsys_user WHERE $col IS NOT NULL ORDER BY $col"), 'v')), static fn($v) => $v !== '')));
				$out[$k] = array_slice($out[$k], 0, 300);
			}
		} catch (Throwable) { /* columns of an older nuBuilder */ }
		// custom user fields (0.8.454): label, type and the values to offer (choices, or the values in use)
		$out['fields'] = [];
		foreach (NuUserFields::all() as $code => $f) {
			if ((string)$f['type'] === 'longtext') continue;
			$vals = [];
			try {
				if ((string)$f['type'] === 'select') $vals = array_map('strval', array_keys(NuUserFields::options($f)));
				elseif ((string)$f['type'] === 'yesno') $vals = ['1', '0'];
				elseif ($db->tableExists(NuUserFields::EXT)) $vals = array_map('strval', array_column($db->page('SELECT DISTINCT ' . NuDB::ident((string)$code) . ' AS v FROM ' . NuUserFields::EXT
					. ' WHERE ' . NuDB::ident((string)$code) . ' IS NOT NULL', [], 'v', 300, 0), 'v'));
			} catch (Throwable) { $vals = []; }
			$out['fields'][] = ['code' => (string)$code, 'label' => NuUserFields::label($f), 'values' => $vals];
		}
		return $out;
	}

	/** A report by id: ['code', 'label', 'row'] or null. */
	public static function report(string $id): ?array
	{
		static $cache = [];
		if ($id === '') return null;
		if (array_key_exists($id, $cache)) return $cache[$id];
		try {
			$db = nu_db();
			$r = $db->tableExists(NuReport::TABLE) ? $db->one('SELECT * FROM zzzzsys_report WHERE zzzzsys_report_id = ?', [$id]) : null;
		} catch (Throwable) {
			$r = null;
		}
		return $cache[$id] = $r ? ['code' => (string)$r['sre_code'], 'label' => (string)($r['sre_description'] ?: $r['sre_code']), 'row' => $r] : null;
	}

	/**
	 * Access levels for the editor's "Start page for" choice: '*' (default) first, then every access level with the source of its
	 * start page (level = own, default, auto).
	 */
	public static function levelChoices(): array
	{
		$rows = self::userRows();
		$out = [self::ALL_LEVELS => ['label' => t('home_u_default'), 'source' => isset($rows[self::ALL_LEVELS]) ? 'level' : 'auto']];
		foreach (nu_db()->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code') as $a) {
			$id = (string)$a['zzzzsys_access_id'];
			$out[$id] = ['label' => $a['sal_code'] . ($a['sal_description'] ? ' – ' . $a['sal_description'] : ''),
				'source' => isset($rows[$id]) ? 'level' : (isset($rows[self::ALL_LEVELS]) ? 'default' : 'auto')];
		}
		return $out;
	}

	/** The access level's own start page goes; it uses the default (or the automatic one) again. '*': the default goes. */
	public static function resetLevel(): string
	{
		if (self::$for === null || !self::$for['preview'] || !self::canManage()) return t('err_forbidden');
		$acc = (string)self::$for['access'];
		$row = self::userRows()[$acc] ?? null;
		if ($row === null) return '';
		$ids = json_decode((string)$row['access_ids'], true);
		$ids = array_values(array_filter(is_array($ids) ? $ids : [], static fn($a) => $a !== $acc));
		$db = nu_db();
		$id = (string)$row['zzzzsys_nunext_list_view_id'];
		if ($ids) $db->update(NuListView::TABLE, ['access_ids' => json_encode($ids), 'updated_at' => date('Y-m-d H:i:s')], 'zzzzsys_nunext_list_view_id', $id);
		else $db->run('DELETE FROM ' . NuListView::TABLE . ' WHERE zzzzsys_nunext_list_view_id = ?', [$id]);
		NuLog::write('home_user_reset', NuListView::TABLE, $id, ['access' => $acc]);
		self::$current = self::$rows = null;
		return '';
	}

	/** Where a tile comes from (0.8.378): builtin (catalogue), pinned (a page of a tile's menu), own (c_…) or dynamic (x_…, start page hook). */
	public static function source(string $key): string
	{
		if (str_starts_with($key, 'x_')) return 'dynamic';
		if (str_starts_with($key, 'c_')) return 'own';
		if (isset(self::catalog()[$key])) return 'builtin';
		return isset(self::subTiles()[$key]) ? 'pinned' : 'own';
	}

	/** Launch form for the globeadmin from $nuConfigGlobeadminHome (id or code); null = this start page ('' / nuhome / unknown form). */
	public static function globeadminForm(): ?string
	{
		$v = trim((string)(nu_cfg('nuConfigGlobeadminHome') ?? ''));
		if ($v === '' || in_array($v, ['nuhome', 'nuuserhome'], true)) return null;
		$forms = nu_forms();
		if (isset($forms[$v]) && $forms[$v]['type'] !== 'subform') return $v;
		foreach ($forms as $id => $f) if (strcasecmp($f['code'], $v) === 0 && $f['type'] !== 'subform') return (string)$id;
		return null;
	}
}
