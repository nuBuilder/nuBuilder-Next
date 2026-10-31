<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Settings centre (0.8.702): one place for every setting of the installation – Admin tools → Settings (?p=settings).
 * A side bar of sections (nav()) with a search over all settings (index(): own settings, security settings, nuBuilder Forte's
 * zzzzsys_config, the variables of nuconfig.php, the editor pages), shown on the settings page itself (sections general, look, data,
 * updates, forte with the simple settings edited in place) and around the big editors, which keep their own pages: branding,
 * themes, e-mail, security check, global code, configuration file – nu_layout_start(…, ['settings' => '<section>']) draws the frame,
 * nu_layout_end() closes it. A new setting = an entry in index() (and its field / page); a new editor page = an entry in pages().
 */
final class NuSettingsHub
{
	/** Sections: id => [icon, title text, description text]. */
	public const SECTIONS = [
		'general'    => ['bi-sliders', 'sh_general', 'sh_general_d'],
		'look'       => ['bi-palette', 'sh_look', 'sh_look_d'],
		'users'      => ['bi-people', 'sh_users', 'sh_users_d'],           // review G1 (0.8.763): links to the user and access tools
		'security'   => ['bi-shield-lock', 'sh_security', 'sh_security_d'],
		'mail'       => ['bi-envelope', 'sh_mail', 'sh_mail_d'],
		'data'       => ['bi-database', 'sh_data', 'sh_data_d'],
		'logs'       => ['bi-journal-text', 'sh_logs', 'sh_logs_d'],       // review G1: logs and integrations
		'code'       => ['bi-code-slash', 'sh_code', 'sh_code_d'],
		'updates'    => ['bi-cloud-arrow-down', 'sh_updates', 'sh_updates_d'],
		'forte'      => ['bi-gear-wide-connected', 'sh_forte', 'sh_forte_d'],
		'configfile' => ['bi-file-earmark-code', 'sh_configfile', 'sh_configfile_d'],
	];

	/**
	 * The tools of the sections users and logs (review G1): key => [icon, title, hint, url] – only what the user may open (the same
	 * rights as the start page tiles, NuHome::visible()). They keep their own pages (lists), the section lists and searches them.
	 */
	public static function tools(string $section): array
	{
		$cat = NuHome::catalog();
		$tile = static function (string $k) use ($cat): ?array {
			if (!isset($cat[$k]) || !NuHome::visible($k, ['custom' => []])) return null;
			[$route, $icon, $label, $hint] = $cat[$k];
			$url = is_array($route) && isset($route[0]) && is_string($route[0]) ? nu_url($route[0], (array)($route[1] ?? [])) : (is_string($route) ? nu_url($route) : '');
			return $url === '' ? null : [$icon, $label, $hint, $url];
		};
		$out = [];
		if ($section === 'users') {
			foreach (['users', 'access', 'adminroles', 'permissions', 'userfields', 'sessions'] as $k) if ($x = $tile($k)) $out[$k] = $x;
		} elseif ($section === 'logs') {
			if (NuAcl::can(NuAcl::FORM_LOG)) $out['log'] = ['bi-journal-text', t('nav_log'), t('sh_log_d'), nu_url('log')];
			if (NuAdmin::can('audit', 'view')) $out['audit'] = ['bi-clock-history', t('aud_title'), t('sh_audit_d'), nu_url('audit')];
			if (NuAcl::can(NuAcl::FORM_EMAILLOG)) $out['emaillog'] = ['bi-envelope-paper', t('mail_tab_log'), t('sh_emaillog_d'), nu_url('email', ['tab' => 'log'])];
			if (NuAcl::can(NuAcl::FORM_DEBUG)) $out['debug'] = ['bi-bug', t('sh_debug'), t('sh_debug_d'), nu_url('debug')];
			foreach (['apiclients', 'webhooks'] as $k) if ($x = $tile($k)) $out[$k] = $x;
		}
		return $out;
	}

	public static bool $open = false;
	private static string $current = '';

	/** The globeadmin (not a demo session). */
	public static function ga(): bool
	{
		$u = NuAuth::user();
		return $u && !empty($u['globeadmin']) && empty($u['demo']);
	}

	/**
	 * Pages of the sections the user may open: section => list of [key, label, url, route, framed]. The first page is the section's
	 * own; framed pages draw the settings frame themselves (nu_layout_start option 'settings').
	 */
	public static function pages(): array
	{
		$can = static fn(string $r): bool => NuAdmin::can($r, 'view');
		$own = static fn(string $s): string => nu_url('settings', ['s' => $s]);
		$p = [
			'general'    => [$can('settings') || $can('setupwizard') ? ['general', t('sh_general'), $own('general'), 'settings', false] : null,
				NuAdmin::can('setupwizard', 'change') ? ['setupwizard', t('wiz_title'), nu_url('setupwizard'), 'setupwizard', false] : null],
			'look'       => [self::ga() || $can('homelayout') || $can('listviews') || $can('branding') ? ['look', t('sh_look'), $own('look'), 'settings', false] : null,
				$can('branding') ? ['branding', t('brand_title'), nu_url('branding'), 'branding', true] : null,
				$can('branding') ? ['themes', t('theme_title'), nu_url('themes'), 'themes', true] : null],
			'users'      => self::toolPages('users'),
			'security'   => [$can('securitycheck') ? ['securitycheck', t('secchk_title'), nu_url('securitycheck'), 'securitycheck', true] : null,
				self::ga() ? ['ipaccess', t('ipa_title'), nu_url('ipaccess'), 'ipaccess', true] : null,
				$can('securitycheck') ? ['pwpolicy', t('pwp_title'), nu_url('pwpolicy'), 'pwpolicy', true] : null],
			'mail'       => [$can('email') ? ['email', t('nav_email'), nu_url('email', ['tab' => 'settings']), 'email', true] : null],
			'data'       => [$can('audit') || $can('update') || $can('notifications') || self::ga() || $can('log') ? ['data', t('sh_data'), $own('data'), 'settings', false] : null],
			'logs'       => self::toolPages('logs'),
			'code'       => [$can('globalcode') ? ['globalcode', t('scode_title'), nu_url('globalcode'), 'globalcode', true] : null,
				$can('globalcode') ? ['jquery', t('jq_title'), nu_url('jquery'), 'globalcode', true] : null],
			'updates'    => [$can('update') ? ['updates', t('sh_updates'), $own('updates'), 'settings', false] : null,
				$can('update') ? ['update', t('upd_title'), nu_url('update'), 'update', false] : null],
			'forte'      => [$can('settings') ? ['forte', t('sh_forte'), $own('forte'), 'settings', false] : null],
			'configfile' => [NuAdmin::can('configfile', 'change') ? ['configfile', 'nuconfig.php', nu_url('configfile'), 'configfile', true] : null],
		];
		$out = [];
		foreach ($p as $s => $list) {
			$list = array_values(array_filter($list));
			if ($list) $out[$s] = $list;
		}
		return $out;
	}

	/** The section page itself + its tools (opened on their own pages, not framed). */
	private static function toolPages(string $section): array
	{
		$tools = self::tools($section);
		if (!$tools) return [];
		$p = [[$section, t(self::SECTIONS[$section][1]), nu_url('settings', ['s' => $section]), 'settings', false]];
		foreach ($tools as $k => [, $label, , $url]) $p[] = ['t_' . $k, $label, $url, $k, false];
		return $p;
	}

	/** May the user open the settings centre at all? */
	public static function allowed(): bool
	{
		return self::pages() !== [];
	}

	/** The side bar: search, overview, sections (the current one with its pages). */
	public static function nav(string $section, string $key = ''): string
	{
		$pages = self::pages();
		$html = '<aside class="nuSetNav" aria-label="' . h(t('sh_title')) . '">'
			. '<div class="nuSetSearch" role="search"><i class="bi bi-search" aria-hidden="true"></i>'
			. '<input type="search" class="form-control form-control-sm" id="nuSetSearch" placeholder="' . h(t('sh_search_ph')) . '" aria-label="' . h(t('sh_search_ph')) . '"'
			. ' autocomplete="off" data-url="' . h(nu_url('settings', ['index' => '1'])) . '" data-none="' . h(t('sh_search_none')) . '" aria-controls="nuSetResults">'
			. '<div class="list-group nuSetResults" id="nuSetResults" role="listbox" hidden></div></div>'
			. '<ul class="nav flex-column nuSetSections">'
			. '<li class="nav-item"><a class="nav-link' . ($section === '' ? ' active" aria-current="page' : '') . '" href="' . h(nu_url('settings')) . '"><i class="bi bi-grid me-2" aria-hidden="true"></i>' . h(t('sh_overview')) . '</a></li>';
		foreach ($pages as $s => $list) {
			[$icon, $title] = self::SECTIONS[$s];
			$on = $s === $section;
			$html .= '<li class="nav-item" data-sh="' . h($s) . '"><a class="nav-link' . ($on ? ' active' : '') . '" href="' . h($list[0][2]) . '"' . ($on && count($list) === 1 ? ' aria-current="page"' : '') . '>'
				. '<i class="bi ' . $icon . ' me-2" aria-hidden="true"></i>' . h(t($title)) . '</a>';
			if ($on && count($list) > 1) {
				$html .= '<ul class="nav flex-column nuSetSub">';
				foreach ($list as [$k, $label, $url]) {
					$cur = $k === $key || ($key === '' && $k === $list[0][0]);
					$html .= '<li class="nav-item"><a class="nav-link' . ($cur ? ' active" aria-current="page' : '') . '" href="' . h($url) . '" data-sh-page="' . h($k) . '">' . h($label) . '</a></li>';
				}
				$html .= '</ul>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></aside>';
	}

	/** Frame around a page (called by nu_layout_start with the option 'settings'): side bar left, the page right. */
	public static function frameStart(string $section, string $key = ''): void
	{
		if (!isset(self::SECTIONS[$section]) && $section !== '') return;
		if (!self::allowed()) return;
		self::$current = $section;
		self::$open = true;
		echo '<div class="nuSetFrame">' . self::nav($section, $key) . '<div class="nuSetMain">';
	}

	/** The section of the open frame ('' = overview or no frame) – for the breadcrumb of nu_page_header(). */
	public static function current(): ?string
	{
		return self::$open ? self::$current : null;
	}

	public static function frameEnd(): void
	{
		if (!self::$open) return;
		self::$open = false;
		echo '</div></div><script src="' . h(nu_asset('assets/js/nusettingshub.js')) . '"></script>';
	}

	/**
	 * Search index: list of [t title, d description, s section title, u link, k keywords (setting names), v current value].
	 * Only what the user may open; values of secret names are left out.
	 */
	public static function index(): array
	{
		$pages = self::pages();
		$out = [];
		$sec = static fn(string $s): string => t(self::SECTIONS[$s][1]);
		$add = static function (string $s, string $title, string $desc, string $url, string $keys = '', string $value = '') use (&$out, $sec, $pages): void {
			if (!isset($pages[$s])) return;
			foreach ($out as &$o) {                                   // the same page twice (its entry + a detailed one, review D8): one entry, the details win
				if ($o['u'] !== $url || $o['t'] !== $title) continue;
				$o['d'] = $desc !== '' ? $desc : $o['d']; $o['k'] = trim($o['k'] . ' ' . $keys); $o['v'] = $value !== '' ? $value : $o['v'];
				return;
			}
			unset($o);
			$out[] = ['t' => $title, 'd' => $desc, 's' => $sec($s), 'u' => $url, 'k' => $keys, 'v' => $value];
		};
		$can = static fn(string $r): bool => NuAdmin::can($r, 'view');
		// the pages themselves
		foreach ($pages as $s => $list) foreach ($list as [$k, $label, $url]) $add($s, $label, t(self::SECTIONS[$s][2]), $url, $k);
		// settings edited on the settings page
		$own = static fn(string $s, string $anchor): string => nu_url('settings', ['s' => $s]) . '#' . $anchor;
		if ($can('settings')) $add('general', t('lng_title'), t('lng_tip'), $own('general', 'setLangs'), 'languages language sprachen', implode(', ', array_map(static fn($c) => NU_LANGS[$c][0], NuLanguages::installed())));
		if (self::ga()) $add('look', t('sb_menu_default'), t('sh_sb_default_tip'), $own('look', 'shSidebar'), 'sidebar_default', t(NuSidebar::defaultOn() ? 'sh_on' : 'sh_off'));
		if ($can('homelayout')) $add('look', t('sh_home_layouts'), t('sh_home_layouts_d'), nu_url('home', ['customize' => '1']), 'home start page layout');
		if ($can('audit')) {
			$a = NuAudit::settings();
			$add('data', t('aud_title') . ' · ' . t('aud_set_on'), t('aud_set_on_tip'), $own('data', 'shAudit'), 'audit', t($a['on'] ? 'sh_on' : 'sh_off'));
			$add('data', t('aud_title') . ' · ' . t('aud_set_keep'), t('aud_set_keep_tip'), $own('data', 'shAudit'), 'audit keep_days', $a['keep_days'] ? t('aud_keep_days', $a['keep_days']) : t('aud_keep_ever'));
			$add('data', t('aud_title') . ' · ' . t('aud_set_exclude'), t('aud_set_exclude_tip'), $own('data', 'shAudit'), 'audit exclude', $a['exclude']);
		}
		if (self::ga() || NuAdmin::can('notifications', 'change')) {
			$k = NuNotify::settings()['keep_days'];
			$add('data', t('ntf_title') . ' · ' . t('ntf_keep'), t('ntf_keep_tip'), $own('data', 'shNotify'), 'notifications keep_days', $k ? t('aud_keep_days', $k) : t('aud_keep_ever'));
		}
		if ($can('update')) {
			$add('data', t('upd_keep_label'), t('upd_keep_hint'), $own('data', 'shBackups'), 'backup_keep_sql backups', (string)NuUpdate::keepSql());
			$r = NuUpdate::cleanupRule();
			$add('data', t('sh_bk_rule'), t('sh_bk_rule_d'), $own('data', 'shBackups'), 'backup_cleanup backups', $r['mode'] === 'age' ? t('upd_bk_clean_age') . ' ' . $r['n'] : t('upd_bk_clean_keep') . ' ' . $r['n']);
			$g = NuGitHub::settings();
			$add('updates', t('gh_repo'), t('gh_repo_tip'), $own('updates', 'shGitHub'), 'github_update github', (string)$g['repo']);
			$add('updates', t('gh_set_every'), t('gh_tip'), $own('updates', 'shGitHub'), 'github auto', t('gh_every_' . $g['every']));
			$add('updates', t('gh_set_pre'), t('gh_tip'), $own('updates', 'shGitHub'), 'github pre');
		}
		if ($can('log') && $can('securitycheck')) $add('data', t('secchk_set_log_keep_days'), t('secchk_set_log_keep_days_tip'), nu_url('securitycheck') . '#sec_log_keep_days', 'log_keep_days log');
		// security settings (Security check)
		if ($can('securitycheck')) $add('security', t('pwp_title'), t('pwp_sub'), nu_url('pwpolicy'), 'password length leaked pwned expiry change complexity');
		if (self::ga()) $add('security', t('ipa_on'), t('ipa_on_tip'), nu_url('ipaccess'), 'ip address network range cidr allow', t(NuIpAccess::on() ? 'sh_on' : 'sh_off'));
		if ($can('securitycheck')) {
			$set = NuSecurity::settings();
			foreach (array_keys(NuSecurity::DEFAULTS) as $k) {
				$v = $set[$k] ?? '';
				$add('security', t('secchk_set_' . $k), t('secchk_set_' . $k . '_tip'), nu_url('securitycheck') . '#sec_' . $k, $k, is_bool($v) ? t($v ? 'sh_on' : 'sh_off') : (string)$v);
			}
		}
		// the user / access and log tools (review G1): found by their names and what they hold
		$kw = ['users' => 'user users login password account', 'access' => 'access level rights roles forms', 'adminroles' => 'admin role rights administrator',
			'permissions' => 'permission item permissions', 'userfields' => 'custom field user fields', 'sessions' => 'session sessions logged in online log out',
			'log' => 'log events sign-in', 'audit' => 'audit history changes restore', 'emaillog' => 'mail e-mail sent log', 'debug' => 'debug results errors',
			'apiclients' => 'api rest token client key', 'webhooks' => 'webhook hook http callback'];
		foreach (['users', 'logs'] as $sx) foreach (self::tools($sx) as $k => [, $label, $hint, $url]) $add($sx, $label, $hint, $url, $k . ' ' . ($kw[$k] ?? ''));
		// the editor pages: what they hold, for the search
		if ($can('branding')) {
			$add('look', t('brand_title'), t('sh_kw_branding'), nu_url('branding'), 'logo name colour color accent favicon');
			$add('look', t('theme_title'), t('sh_kw_themes'), nu_url('themes'), 'theme dark light colours');
		}
		if ($can('email')) $add('mail', t('nav_email'), t('sh_kw_mail'), nu_url('email', ['tab' => 'settings']), 'smtp oauth mail sender host port');
		if ($can('globalcode')) $add('code', t('scode_title'), t('sh_kw_code'), nu_url('globalcode'), 'javascript css header include set_header set_style');
		if ($can('globalcode')) $add('code', t('jq_title'), t('jq_sub'), nu_url('jquery'), 'jquery migrate 4.0 version');
		// nuBuilder Forte's settings (zzzzsys_config)
		if ($can('settings') && NuSettings::available()) {
			foreach (NuSettings::rows() as $r) {
				$name = (string)$r['cfg_setting'];
				$val = NuSecurity::secretName($name) ? '' : mb_substr((string)($r['cfg_value'] ?? ''), 0, 80);
				$add('forte', (string)($r['cfg_title'] ?: $name), (string)($r['cfg_description'] ?? ''), nu_url('settings', ['s' => 'forte', 'q' => $name]), $name, $val);
			}
		}
		// variables of nuconfig.php (no values: they may be secrets)
		if (NuAdmin::can('configfile', 'change')) {
			foreach (NuConfigFile::catalog() as $c) $add('configfile', '$' . $c['name'], (string)$c['comment'], nu_url('configfile', ['find' => $c['name']]), (string)$c['name']);
		}
		return $out;
	}
}
