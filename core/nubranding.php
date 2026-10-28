<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Branding per installation (0.8.297, Admin tools → Branding): own logo (light / dark mode), accent colour, title next to the logo,
 * logo as browser icon, environment label ("TEST") with its colour.
 *   - settings: one JSON row 'branding' in zzzzsys_nunext_setting;
 *   - logos: nuBuilder's file table zzzzsys_file (ids / codes nunext_logo and nunext_logo_dark) – they travel with backups and the
 *     App cloner and can be seen and replaced in nuBuilder's Files form as well;
 *   - the accent colour and the label colour come as a generated stylesheet (?p=brand&a=css&v=<hash>) – the strict CSP allows no
 *     inline styles; the hash changes with every change, so the file can be cached for good;
 *   - the environment label can also come from nuconfig.php ($nuConfigEnvironmentLabel, $nuConfigEnvironmentColor) and then wins:
 *     a database copied to a test server keeps the label of the server it runs on, not of the one it came from.
 * Never fails: without nuconfig.php, without a database (error pages, installer) everything stays nuBuilder Next's default.
 */
final class NuBranding
{
	public const SETTING = 'branding';
	public const FILE_LIGHT = 'nunext_logo';
	public const FILE_DARK = 'nunext_logo_dark';
	/** Largest logo (bytes). */
	public const MAX_BYTES = 524288;
	public const TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml'];
	public const DEFAULTS = ['accent' => '', 'logo' => false, 'logo_dark' => false, 'show_title' => false, 'favicon' => false, 'logo_show' => 'all',
		'env_label' => '', 'env_color' => '#dc3545', 'rev' => ''];
	public const ENV_LEN = 20;
	/** Where the logo shows (0.8.630): all = top bar and sign-in pages, login = sign-in pages only, none = nowhere. */
	public const LOGO_SHOW = ['all', 'login', 'none'];

	private static ?array $cache = null;
	private static bool $busy = false;
	/** Error pages and the installer: no database access – default look. */
	public static bool $off = false;

	/** The stored settings merged with the defaults (and the environment label of nuconfig.php). */
	public static function get(): array
	{
		if (self::$cache !== null) return self::$cache;
		$out = self::DEFAULTS;
		if (self::$off || self::$busy) return $out;
		self::$busy = true;
		try {
			if (is_file(nu_config_path())) {
				$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
				$stored = is_string($v) ? (json_decode($v, true) ?: []) : [];
				foreach (self::DEFAULTS as $k => $d) if (array_key_exists($k, $stored)) $out[$k] = is_bool($d) ? (bool)$stored[$k] : (string)$stored[$k];
				$cfg = nu_config();
				if (is_string($cfg['nuConfigEnvironmentLabel'] ?? null)) $out['env_label'] = $cfg['nuConfigEnvironmentLabel'];
				if (is_string($cfg['nuConfigEnvironmentColor'] ?? null) && self::hex($cfg['nuConfigEnvironmentColor']) !== '') $out['env_color'] = $cfg['nuConfigEnvironmentColor'];
			}
		} catch (Throwable) {
			$out = self::DEFAULTS;
		}
		self::$busy = false;
		$out['accent'] = self::hex($out['accent']);
		$out['env_color'] = self::hex($out['env_color']) ?: self::DEFAULTS['env_color'];
		$out['env_label'] = mb_substr(trim($out['env_label']), 0, self::ENV_LEN);
		if (!in_array($out['logo_show'], self::LOGO_SHOW, true)) $out['logo_show'] = 'all';
		return self::$cache = $out;
	}

	/** True when the label comes from nuconfig.php (the field on the page is then read-only). */
	public static function envFromConfig(): bool
	{
		try { return is_file(nu_config_path()) && is_string(nu_config()['nuConfigEnvironmentLabel'] ?? null); } catch (Throwable) { return false; }
	}

	/** '#rrggbb' in lower case, or '' when it is not a colour. */
	public static function hex(string $c): string
	{
		$c = strtolower(trim($c));
		if (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/', $c, $m)) return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
		return preg_match('/^#?([0-9a-f]{6})$/', $c, $m) ? '#' . $m[1] : '';
	}

	/** Version of the generated stylesheet / logos ('' = nothing to load). */
	public static function version(): string
	{
		$b = self::get();
		if ($b['accent'] === '' && $b['env_label'] === '') return '';
		return substr(md5(json_encode([$b['accent'], $b['env_label'] !== '' ? $b['env_color'] : '', $b['rev']])), 0, 10);
	}

	// ------------------------------------------------------------------ output helpers (layout)

	/** <link> tags for nu_head(): the brand stylesheet and the browser icon. */
	public static function headTags(): string
	{
		$b = self::get();
		$out = '';
		if (($v = self::version()) !== '') $out .= '<link rel="stylesheet" href="' . h(nu_url('brand', ['a' => 'css', 'v' => $v])) . '">' . "\n\t";
		if ($b['favicon'] && $b['logo']) $out .= '<link rel="icon" href="' . h(self::logoUrl(false)) . '">';
		else $out .= '<link rel="icon" href="' . nu_asset('assets/img/favicon.svg') . '" type="image/svg+xml">';
		return $out;
	}

	public static function logoUrl(bool $dark): string
	{
		return nu_url('brand', ['a' => $dark ? 'logodark' : 'logo', 'v' => self::get()['rev']]);
	}

	/** The logo for the top bar ('') or the sign-in pages ('lg'): own logo when uploaded, otherwise nuBuilder Next's. */
	public static function logo(string $size = ''): string
	{
		$b = self::get();
		if (!$b['logo']) return '';
		$title = nu_app_title();
		$cls = 'nu-brand nu-brand-own' . ($size === 'lg' ? ' nu-brand-lg' : '');
		$img = $b['logo_dark']
			? '<img class="nu-brand-light" src="' . h(self::logoUrl(false)) . '" alt="' . h($title) . '"><img class="nu-brand-dark" src="' . h(self::logoUrl(true)) . '" alt="' . h($title) . '">'   // one of the two is shown: both carry the name (0.8.368)
			: '<img src="' . h(self::logoUrl(false)) . '" alt="' . h($title) . '">';
		$text = $b['show_title'] && $size === '' && nu_app_title(true) !== '' ? '<span class="nu-brand-title">' . h(nu_app_title(true)) . '</span>' : '';
		return '<span class="' . $cls . '">' . $img . '</span>' . $text;
	}

	/** Is the logo shown in the top bar ('') or on the sign-in pages ('lg')? (0.8.630) */
	public static function logoShown(string $size = ''): bool
	{
		$w = self::get()['logo_show'];
		return $w === 'all' || ($w === 'login' && $size === 'lg');
	}

	/** Top bar without logo: the title alone when "show title" is on. */
	public static function titleNoLogo(): string
	{
		$b = self::get();
		return $b['show_title'] && nu_app_title(true) !== '' ? '<span class="nu-brand-title">' . h(nu_app_title(true)) . '</span>' : '';
	}

	/** Title next to nuBuilder Next's own logo (top bar) when "show title" is on but no own logo was uploaded. */
	public static function titleOnly(): string
	{
		$b = self::get();
		return !$b['logo'] && $b['show_title'] && nu_app_title(true) !== '' ? '<span class="nu-brand-title">' . h(nu_app_title(true)) . '</span>' : '';
	}

	/** The environment label as a badge ('' when none). */
	public static function envBadge(string $extra = ''): string
	{
		$l = self::get()['env_label'];
		return $l === '' ? '' : '<span class="nuEnvLabel' . ($extra !== '' ? ' ' . $extra : '') . '" title="' . h(t('brand_env_tip')) . '">' . h($l) . '</span>';
	}

	// ------------------------------------------------------------------ colours

	public static function rgb(string $hex): array
	{
		return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
	}

	private static function toHex(array $c): string
	{
		return sprintf('#%02x%02x%02x', ...array_map(static fn($v) => (int)max(0, min(255, round($v))), $c));
	}

	/** $hex mixed with $with ('#fff' = tint, '#000' = shade) by $p (0–1). */
	public static function mix(string $hex, string $with, float $p): string
	{
		$a = self::rgb($hex); $b = self::rgb(self::hex($with));
		return self::toHex([$a[0] + ($b[0] - $a[0]) * $p, $a[1] + ($b[1] - $a[1]) * $p, $a[2] + ($b[2] - $a[2]) * $p]);
	}

	/** WCAG relative luminance. */
	public static function luminance(string $hex): float
	{
		$l = array_map(static function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, self::rgb($hex));
		return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
	}

	/** WCAG contrast ratio of two colours (1–21). */
	public static function contrast(string $a, string $b): float
	{
		$x = self::luminance($a); $y = self::luminance($b);
		return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
	}

	/** Text colour on the accent: white while it is readable (3:1, as large / bold button text), else black. */
	public static function onColor(string $hex): string
	{
		return self::contrast($hex, '#ffffff') >= 3 ? '#fff' : '#000';
	}

	/** $hex made darker (towards black) or lighter (towards white) until it reaches $ratio against $bg. */
	public static function readable(string $hex, string $bg, float $ratio, string $towards): string
	{
		$c = $hex;
		for ($p = 0.0; $p <= 1.0001 && self::contrast($c, $bg) < $ratio; $p += 0.05) $c = self::mix($hex, $towards, $p);
		return $c;
	}

	/** The generated stylesheet: accent colour (Bootstrap's primary in all its uses, nuBuilder Next's accent and tab line, --nu-on-accent = text on
	 *  it, e.g. form buttons) and the label. */
	public static function css(): string
	{
		$b = self::get();
		$o = "/* nuBuilder Next – branding of this installation (Admin tools → Branding) */\n";
		if (($c = $b['accent']) !== '') $o .= self::accentCss($c);
		if ($b['env_label'] !== '') {
			$e = $b['env_color'];
			$o .= ":root { --nu-env: $e; }\n"
				. ".nuEnvLabel { background: $e; color: " . self::onColor($e) . "; }\n";
		}
		return $o;
	}

	// ------------------------------------------------------------------ saving

	/**
	 * Saves the settings and the uploaded logos.
	 * $files: ['light' => ['content', 'name'] | null, 'dark' => …]; $remove: ['light' => bool, 'dark' => bool].
	 * @return array{0: array, 1: array} [errors, warnings]
	 */
	public static function save(array $in, array $files, array $remove): array
	{
		$errors = []; $warn = [];
		$old = self::get();
		$new = $old;
		foreach (['light' => self::FILE_LIGHT, 'dark' => self::FILE_DARK] as $k => $fid) {
			if (($f = $files[$k] ?? null) === null) continue;
			$type = self::imageType($f['content'], (string)$f['name']);
			if (strlen($f['content']) > self::MAX_BYTES) $errors['logo_' . $k] = t('brand_err_size', (int)(self::MAX_BYTES / 1024));
			elseif ($type === '') $errors['logo_' . $k] = t('brand_err_type');
		}
		$accent = !empty($in['accent_on']) ? self::hex((string)($in['accent'] ?? '')) : '';
		if (!empty($in['accent_on']) && $accent === '') $errors['accent'] = t('brand_err_color');
		$label = mb_substr(trim((string)($in['env_label'] ?? '')), 0, self::ENV_LEN);
		$envColor = self::hex((string)($in['env_color'] ?? '')) ?: self::DEFAULTS['env_color'];
		if ($errors) return [$errors, []];

		$db = nu_db();
		NuFile::ensureTable();
		foreach (['light' => [self::FILE_LIGHT, 'logo'], 'dark' => [self::FILE_DARK, 'logo_dark']] as $k => [$fid, $flag]) {
			if (($f = $files[$k] ?? null) !== null) {
				$json = NuFile::encode($f['content'], (string)$f['name']);
				$row = ['sfi_code' => $fid, 'sfi_description' => $k === 'light' ? 'nuBuilder Next logo' : 'nuBuilder Next logo (dark mode)', 'sfi_group' => 'nunext', 'sfi_json' => $json];
				if ($db->value('SELECT COUNT(*) FROM zzzzsys_file WHERE zzzzsys_file_id = ?', [$fid])) $db->update(NuFile::TABLE, $row, 'zzzzsys_file_id', $fid);
				else {
					$db->run('DELETE FROM zzzzsys_file WHERE sfi_code = ?', [$fid]);          // a row with this code but another id
					$db->insert(NuFile::TABLE, ['zzzzsys_file_id' => $fid] + $row + ['sfi_system' => '0']);
				}
				$new[$flag] = true;
			} elseif (!empty($remove[$k])) {
				$db->run('DELETE FROM zzzzsys_file WHERE zzzzsys_file_id = ?', [$fid]);
				$new[$flag] = false;
			}
		}
		$new['accent'] = $accent;
		$new['show_title'] = !empty($in['show_title']);
		$new['favicon'] = !empty($in['favicon']);
		$new['logo_show'] = in_array((string)($in['logo_show'] ?? 'all'), self::LOGO_SHOW, true) ? (string)$in['logo_show'] : 'all';
		if (!self::envFromConfig()) $new['env_label'] = $label;
		$new['env_color'] = $envColor;
		if ($accent !== '' && self::contrast($accent, '#ffffff') < 3) $warn[] = t('brand_warn_light');

		$store = array_intersect_key($new, self::DEFAULTS);
		if (self::envFromConfig()) {                                       // keep what was stored; the label of nuconfig.php is not saved
			$v = $db->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$store['env_label'] = (string)((is_string($v) ? (json_decode($v, true) ?: []) : [])['env_label'] ?? '');
		}
		$changed = [];
		foreach ($store as $k => $v) if ($k !== 'rev' && $v !== ($old[$k] ?? null)) $changed[$k] = [$old[$k] ?? null, $v];
		if (array_filter($files)) $changed['logo_new'] = [false, true];
		if (!$changed && !array_filter($remove)) return [[], $warn];
		$store['rev'] = substr(md5(uniqid('', true)), 0, 8);
		NuSchema::ensure();
		$row = ['setting_value' => json_encode($store), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
		self::$cache = null;
		NuLog::write('branding_saved', 'zzzzsys_nunext_setting', self::SETTING, ['changed' => array_keys($changed)]);
		return [[], $warn];
	}

	/** Image type of an upload from its content ('' when it is not one of TYPES). An SVG must really be one. */
	public static function imageType(string $content, string $name): string
	{
		$type = '';
		if (class_exists('finfo')) $type = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($content);
		if (in_array($type, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) return @getimagesizefromstring($content) !== false ? $type : '';
		$head = ltrim(substr($content, 0, 2048), "\xEF\xBB\xBF \t\r\n");
		if (preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $head)) return 'image/svg+xml';
		return '';
	}

	/** Reset to nuBuilder Next's look (logos removed, settings deleted). */
	public static function reset(): void
	{
		$db = nu_db();
		if ($db->tableExists(NuFile::TABLE)) $db->run('DELETE FROM zzzzsys_file WHERE zzzzsys_file_id IN (?, ?)', [self::FILE_LIGHT, self::FILE_DARK]);
		$db->run('DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
		self::$cache = null;
		NuLog::write('branding_reset', 'zzzzsys_nunext_setting', self::SETTING);
	}

	/** CSS of an accent colour (Bootstrap's primary in all its uses, --nu-accent, --nu-on-accent, tab line) – Branding and themes (0.8.632). */
	public static function accentCss(string $c): string
	{
		$rgb = implode(', ', self::rgb($c));
		$on = self::onColor($c);
		$link = self::readable($c, '#ffffff', 4.5, '#000000');                 // links stay readable on white
		$linkD = self::readable(self::mix($c, '#ffffff', 0.4), '#212529', 4.5, '#ffffff');
		$hover = self::mix($c, '#000000', 0.15); $active = self::mix($c, '#000000', 0.2);
		$focus = self::mix($c, '#ffffff', 0.5);
		$o = ":root, [data-bs-theme=\"light\"] {\n"
			. "\t--bs-primary: $c; --bs-primary-rgb: $rgb; --nu-accent: $c; --nu-on-accent: $on; --nu-tab-active: $c;\n"
			. "\t--bs-primary-text-emphasis: " . self::mix($c, '#000000', 0.6) . '; --bs-primary-bg-subtle: ' . self::mix($c, '#ffffff', 0.8)
			. '; --bs-primary-border-subtle: ' . self::mix($c, '#ffffff', 0.6) . ";\n"
			. "\t--bs-link-color: $link; --bs-link-color-rgb: " . implode(', ', self::rgb($link)) . '; --bs-link-hover-color: ' . self::mix($link, '#000000', 0.2)
			. '; --bs-link-hover-color-rgb: ' . implode(', ', self::rgb(self::mix($link, '#000000', 0.2))) . ";\n"
			. "\t--bs-focus-ring-color: rgba($rgb, .25);\n}\n"
			. "[data-bs-theme=\"dark\"] {\n"
			. "\t--bs-primary: $c; --bs-primary-rgb: $rgb; --nu-accent: $c; --nu-on-accent: $on; --nu-tab-active: " . self::mix($c, '#ffffff', 0.3) . ";\n"
			. "\t--bs-primary-text-emphasis: " . self::mix($c, '#ffffff', 0.4) . '; --bs-primary-bg-subtle: ' . self::mix($c, '#000000', 0.8)
			. '; --bs-primary-border-subtle: ' . self::mix($c, '#000000', 0.4) . ";\n"
			. "\t--bs-link-color: $linkD; --bs-link-color-rgb: " . implode(', ', self::rgb($linkD)) . '; --bs-link-hover-color: ' . self::mix($linkD, '#ffffff', 0.2)
			. '; --bs-link-hover-color-rgb: ' . implode(', ', self::rgb(self::mix($linkD, '#ffffff', 0.2))) . ";\n}\n"
			. ".btn-primary { --bs-btn-color: $on; --bs-btn-bg: $c; --bs-btn-border-color: $c; --bs-btn-hover-color: $on; --bs-btn-hover-bg: $hover; --bs-btn-hover-border-color: $active;"
			. " --bs-btn-focus-shadow-rgb: $rgb; --bs-btn-active-color: $on; --bs-btn-active-bg: $active; --bs-btn-active-border-color: $active;"
			. " --bs-btn-disabled-color: $on; --bs-btn-disabled-bg: $c; --bs-btn-disabled-border-color: $c; }\n"
			. ".btn-outline-primary { --bs-btn-color: var(--bs-link-color); --bs-btn-border-color: $c; --bs-btn-hover-color: $on; --bs-btn-hover-bg: $c; --bs-btn-hover-border-color: $c;"
			. " --bs-btn-focus-shadow-rgb: $rgb; --bs-btn-active-color: $on; --bs-btn-active-bg: $c; --bs-btn-active-border-color: $c; --bs-btn-disabled-color: $c; --bs-btn-disabled-border-color: $c; }\n"
			. ".text-bg-primary { color: $on !important; background-color: RGBA($rgb, var(--bs-bg-opacity, 1)) !important; }\n"
			. ".form-check-input:checked { background-color: $c; border-color: $c; }\n"
			. ".form-check-input:focus, .form-control:focus, .form-select:focus { border-color: $focus; box-shadow: 0 0 0 .25rem rgba($rgb, .25); }\n"
			. ".nav-pills { --bs-nav-pills-link-active-bg: $c; --bs-nav-pills-link-active-color: $on; }\n"
			. ".dropdown-menu { --bs-dropdown-link-active-bg: $c; --bs-dropdown-link-active-color: $on; }\n"
			. ".list-group { --bs-list-group-active-bg: $c; --bs-list-group-active-border-color: $c; --bs-list-group-active-color: $on; }\n"
			. ".pagination { --bs-pagination-active-bg: $c; --bs-pagination-active-border-color: $c; --bs-pagination-active-color: $on; }\n"
			. ".progress, .progress-stacked { --bs-progress-bar-bg: $c; }\n"
			. ".form-range::-webkit-slider-thumb { background-color: $c; }\n.form-range::-moz-range-thumb { background-color: $c; }\n";
		return $o;
	}

	// ------------------------------------------------------------------ public route ?p=brand

	/** ?p=brand&a=css|logo|logodark – no login needed (sign-in pages); ends the request. */
	public static function serve(string $a): never
	{
		if ($a === 'css') {
			header('Content-Type: text/css; charset=utf-8');
			header('X-Content-Type-Options: nosniff');
			header('Cache-Control: public, max-age=31536000, immutable');   // the URL carries the version
			echo self::css();
			exit;
		}
		$b = self::get();
		$id = $a === 'logodark' && $b['logo_dark'] ? self::FILE_DARK : self::FILE_LIGHT;
		$row = null;
		try { if ($b['logo'] && nu_db()->tableExists(NuFile::TABLE)) $row = nu_db()->one('SELECT sfi_json FROM zzzzsys_file WHERE zzzzsys_file_id = ?', [$id]); } catch (Throwable) {}
		$info = $row ? NuFile::info((string)$row['sfi_json']) : [];
		if (!$info || !preg_match('#^data:(image/[\w.+-]+)(;base64)?,#', $info['url'], $m) || !in_array($m[1], self::TYPES, true)) {
			// the logo was deleted (e.g. in the Files form): nuBuilder Next's own logo instead of a broken image
			header('Content-Type: image/png');
			header('Cache-Control: public, max-age=600');
			readfile(NU_ROOT . '/assets/img/' . ($a === 'logodark' ? 'nunext-logo-dark.png' : 'nunext-logo.png'));
			exit;
		}
		$body = substr($info['url'], strlen($m[0]));
		$body = $m[2] !== '' ? (string)base64_decode($body) : rawurldecode($body);
		$etag = '"' . substr(md5($body), 0, 16) . '"';
		header('Content-Type: ' . $m[1]);
		header('X-Content-Type-Options: nosniff');
		header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");   // an SVG opened directly cannot run script
		header('Cache-Control: public, max-age=600');
		header('ETag: ' . $etag);
		if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
		header('Content-Length: ' . strlen($body));
		echo $body;
		exit;
	}
}
