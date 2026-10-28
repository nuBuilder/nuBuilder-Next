<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Global JS & CSS (0.8.523) – the second layer of Admin tools → Custom code: JavaScript and CSS for every page of nuBuilder Next,
 * next to nuBuilder's Setup → Header / Style / Include (which stay form-only, as in nuBuilder).
 *   storage   settings rows custom_js, custom_css, custom_scope ({"forms":1,"app":1,"login":0}) – never zzzzsys_setup, so nuBuilder is untouched
 *   output    as files of this server (?p=globalcodefile&t=js|css&v=<hash>) – the strict Content-Security-Policy of nuBuilder Next's pages
 *             stays; CSS in the head (before the page's own styles), JS before </body> (on form pages before Setup → Header and the
 *             form's code, so nuOnLoad / nuLoadBrowseGlobal of a form always win)
 *   never     on the Custom code page itself and the Update page, and not with ?safe=1 (globeadmin / admin right globalcode)
 *   hook      <body data-nu-page data-nu-mode data-nu-form data-nu-admin> + nuOnPageLoad(page, info) and the event nu:pageload
 *             (assets/js/nuapp.js; on form pages called by the runtime after nuOnLoad)
 */
final class NuCustomCode
{
	public const KEYS = ['js' => 'custom_js', 'css' => 'custom_css'];
	public const SCOPE_KEY = 'custom_scope';
	public const SCOPES = ['forms' => true, 'app' => true, 'login' => false];   // defaults
	/** Pages that never load global code: the editor (to repair it) and the update page (to roll back a release). */
	public const EXCLUDED = ['globalcode', 'update'];
	/** nuBuilder's hook names – global code should not define them (the compatibility check warns). */
	public const NB_HOOKS = ['nuOnLoad', 'nuLoadBrowseGlobal', 'nuLoadEditGlobal', 'nuBeforeSave', 'nuAfterSave', 'nuBeforeDelete', 'nuAfterDelete',
		'nuOnSave', 'nuOnClone', 'nuBeforeSaveGlobal', 'nuAfterSaveGlobal', 'nuSelectBrowse', 'nuOnSetSaved', 'nuOnTabSelected'];

	/** 'app' (layout pages), 'login' (sign-in, reset, two-factor) or 'none' (error pages) – set by the layout before nu_head(). */
	public static string $context = 'app';
	private static ?array $cache = null;

	/** @return array{js: string, css: string, scope: array<string, bool>} */
	public static function get(): array
	{
		if (self::$cache !== null) return self::$cache;
		$out = ['js' => '', 'css' => '', 'scope' => self::SCOPES];
		try {
			// installer (no nuconfig.php yet): nu_db() would end the request with "nuconfig.php not found" (0.8.552)
			if (!is_file(nu_config_path()) || !nu_db()->tableExists('zzzzsys_nunext_setting')) return self::$cache = $out;
			$rows = nu_db()->all('SELECT zzzzsys_nunext_setting_id AS k, setting_value AS v FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN (?, ?, ?)',
				[self::KEYS['js'], self::KEYS['css'], self::SCOPE_KEY]);
			foreach ($rows as $r) {
				if ($r['k'] === self::KEYS['js']) $out['js'] = (string)$r['v'];
				elseif ($r['k'] === self::KEYS['css']) $out['css'] = (string)$r['v'];
				else {
					$s = json_decode((string)$r['v'], true);
					if (is_array($s)) foreach (self::SCOPES as $k => $d) $out['scope'][$k] = !empty($s[$k] ?? $d);
				}
			}
		} catch (Throwable) {
			/* no database (installer, error pages): nothing */
		}
		return self::$cache = $out;
	}

	public static function hash(string $code): string
	{
		return substr(hash('sha256', $code), 0, 16);
	}

	/** Save JS, CSS and scope; returns the names of the changed parts. */
	/**
	 * Global CSS is served as a .css file, where <style> is not CSS (the browser skips the rule it runs into). CSS pasted from Form CSS
	 * often comes wrapped: when the text starts with <style …> and ends with </style>, every <style …> / </style> tag is removed
	 * (several blocks too). @return ?string the CSS without the tags, null when it was not wrapped (0.8.557)
	 */
	public static function unwrapStyle(string $css): ?string
	{
		$t = trim($css);
		if (!preg_match('#^<style\b[^>]*>#i', $t) || !preg_match('#</style>$#i', $t)) return null;
		return trim((string)preg_replace('#</?style\b[^>]*>#i', '', $t)) . "\n";
	}

	public static function save(?string $js, ?string $css, ?array $scope): array
	{
		NuSchema::ensure();
		$db = nu_db();
		$cur = self::get();
		$who = mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50);
		$put = static function (string $id, string $value) use ($db, $who): void {
			$row = ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $who];
			if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', $id);
			else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $id] + $row);
		};
		$changed = [];
		if ($js !== null && $js !== $cur['js']) { $put(self::KEYS['js'], $js); $changed[] = 'js'; }
		if ($css !== null && $css !== $cur['css']) { $put(self::KEYS['css'], $css); $changed[] = 'css'; }
		if ($scope !== null) {
			$new = [];
			foreach (self::SCOPES as $k => $d) $new[$k] = !empty($scope[$k]);
			if ($new !== $cur['scope']) { $put(self::SCOPE_KEY, (string)json_encode(array_map('intval', $new))); $changed[] = 'scope'; }
		}
		self::$cache = null;
		return $changed;
	}

	/** The current route (?p=, as index.php reads it). */
	public static function page(): string
	{
		$p = (string)($_GET['p'] ?? 'home');
		return preg_match('/^[a-z0-9_]{1,40}$/', $p) ? $p : 'home';
	}

	/** Is ?safe=1 honoured? Only for the globeadmin and admins with the right Custom code – everybody else always gets the code. */
	public static function safeMode(): bool
	{
		if (($_GET['safe'] ?? '') !== '1') return false;
		try {
			$u = NuAuth::user();
			return $u !== null && (!empty($u['globeadmin']) || NuAdmin::can('globalcode', 'view'));
		} catch (Throwable) {
			return false;
		}
	}

	/** Does this request get the global code of this kind (js / css)? */
	public static function active(string $kind): bool
	{
		if (self::$context === 'none' || in_array(self::page(), self::EXCLUDED, true) || self::safeMode()) return false;
		$c = self::get();
		if (trim($c[$kind] ?? '') === '') return false;
		$scope = self::$context === 'login' ? 'login' : (self::page() === 'form' ? 'forms' : 'app');
		return !empty($c['scope'][$scope]);
	}

	public static function url(string $kind): string
	{
		return nu_url('globalcodefile', ['t' => $kind, 'v' => self::hash(self::get()[$kind])]);
	}

	public static function cssTag(): string
	{
		return self::active('css') ? '<link rel="stylesheet" href="' . h(self::url('css')) . '" data-nu-source="custom_css">' : '';
	}

	public static function jsTag(): string
	{
		return self::active('js') ? '<script src="' . h(self::url('js')) . '" data-nu-source="custom_js"></script>' : '';
	}

	/** Attributes of <body>: the page hook for CSS and nuOnPageLoad. $form = [mode, form code] on form pages. */
	public static function bodyAttrs(?array $form = null): string
	{
		$a = ' data-nu-page="' . h(self::$context === 'login' && self::page() === 'home' ? 'login' : self::page()) . '"';
		if ($form) $a .= ' data-nu-mode="' . h((string)$form[0]) . '" data-nu-form="' . h((string)$form[1]) . '"';
		if (self::$context === 'app' && ($help = NuHelpLink::url(self::page())) !== '') $a .= ' data-nu-help="' . h($help) . '"';   // F1 (0.8.528)
		if (self::$context === 'app') $a .= ' data-nu-tab-sections="' . h(t('tab_sections')) . '"';   // label of the ▾ in tabs (0.8.639, nuapp.js)
		try {
			$u = NuAuth::user();
			if ($u !== null && (!empty($u['globeadmin']) || NuAdmin::any())) $a .= ' data-nu-admin="1"';
		} catch (Throwable) {
			/* before the set-up */
		}
		return $a;
	}

	/** ?p=globalcodefile&t=js|css&v=<hash> – the file. Signed-in users; without a session only while the scope "sign-in pages" is on. */
	public static function serve(): never
	{
		$kind = ($_GET['t'] ?? '') === 'css' ? 'css' : 'js';
		$c = self::get();
		$user = null;
		try { $user = NuAuth::user(); } catch (Throwable) { /* no session */ }
		session_write_close();
		header('Content-Type: ' . ($kind === 'css' ? 'text/css' : 'text/javascript') . '; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		if ($user === null && empty($c['scope']['login'])) {
			http_response_code(403);
			header('Cache-Control: no-store');
			exit('');
		}
		$fresh = (string)($_GET['v'] ?? '') === self::hash($c[$kind]);
		header('Cache-Control: ' . ($fresh ? 'private, max-age=31536000, immutable' : 'no-cache'));
		exit('/* nuBuilder Next – Global ' . strtoupper($kind) . " (Admin tools → Custom code) */\n" . $c[$kind] . "\n");
	}

	/** nuBuilder hook names the global JavaScript defines (function x / x = / window.x =) – for the compatibility check and the editor. */
	public static function hookClashes(?string $js = null): array
	{
		$js ??= self::get()['js'];
		$found = [];
		foreach (self::NB_HOOKS as $n) {
			if (preg_match('/\bfunction\s+' . $n . '\s*\(|(?:window\.|\bvar\s+|\blet\s+|\bconst\s+)' . $n . '\s*=/', $js)) $found[] = $n;
		}
		return $found;
	}
}
