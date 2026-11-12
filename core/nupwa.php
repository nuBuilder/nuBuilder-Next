<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Installable app (0.8.1136): web manifest, icons, service worker, offline page.
 *   ?p=pwa&a=manifest   manifest (name / colours from Branding; shortcuts = the signed-in user's favourites)
 *   ?p=pwa&a=icon&s=192|512|180&m=1   icon (own logo of Branding when "Use as browser icon" is on, else nuBuilder Next's)
 *   ?p=pwa&a=sw         service worker – scope = the installation's folder; caches only versioned static files, pages never
 *   ?p=pwa&a=offline    page shown when the server cannot be reached (cached by the worker)
 * Switch: Branding → "Installable app" (key pwa, default on). Off = no manifest, and the worker removes itself.
 */
final class NuPwa
{
	public const SIZES = [180, 192, 512];

	public static function enabled(): bool
	{
		try { return (bool)NuBranding::get()['pwa']; } catch (Throwable) { return true; }
	}

	/** Version of everything the worker caches: release + branding (a new logo / name gives a new cache). */
	public static function version(): string
	{
		return substr(hash('sha256', NU_VERSION . '|' . NuBranding::get()['rev'] . '|' . (self::enabled() ? '1' : '0')), 0, 12);
	}

	/** Tags for nu_head: manifest, theme colour, iOS icon + title, the registering script. */
	public static function headTags(): string
	{
		$sw = h(nu_url('pwa', ['a' => 'sw']));
		$js = '<script src="' . nu_asset('assets/js/nupwa.js') . '" defer></script>';
		if (!self::enabled()) return '<meta name="nu-pwa" content="off" data-sw="' . $sw . '">' . $js;   // removes a worker of earlier days
		$v = self::version();
		$title = h(self::shortName());
		return '<link rel="manifest" href="' . h(nu_url('pwa', ['a' => 'manifest', 'v' => $v])) . '" crossorigin="use-credentials">'
			. '<meta name="theme-color" content="' . h(self::color()) . '">'
			. '<link rel="apple-touch-icon" href="' . h(nu_url('pwa', ['a' => 'icon', 's' => 180, 'm' => 1, 'v' => $v])) . '">'
			. '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">'
			. '<meta name="apple-mobile-web-app-title" content="' . $title . '">'
			. '<meta name="nu-pwa" content="on" data-sw="' . $sw . '">' . $js;
	}

	private static function color(): string
	{
		$a = (string)NuBranding::get()['accent'];
		return preg_match('/^#[0-9a-f]{6}$/i', $a) ? $a : '#2f6fd6';
	}

	public static function name(): string
	{
		return mb_substr(nu_app_title(), 0, 60);
	}

	public static function shortName(): string
	{
		$b = NuBranding::get();
		$s = trim((string)$b['title_phone']) !== '' ? (string)$b['title_phone'] : self::name();
		return mb_substr($s, 0, 20);
	}

	/** @return array<string, mixed> */
	public static function manifest(): array
	{
		$v = self::version();
		$icons = [];
		foreach ([192, 512] as $s) $icons[] = ['src' => nu_url('pwa', ['a' => 'icon', 's' => $s, 'v' => $v]), 'sizes' => "{$s}x{$s}", 'type' => 'image/png', 'purpose' => 'any'];
		$icons[] = ['src' => nu_url('pwa', ['a' => 'icon', 's' => 512, 'm' => 1, 'v' => $v]), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
		$m = ['id' => './', 'name' => self::name(), 'short_name' => self::shortName(), 'lang' => nu_lang_choice(), 'dir' => nu_lang_rtl() ? 'rtl' : 'ltr',
			'start_url' => nu_url('home', ['src' => 'app']), 'scope' => './', 'display' => 'standalone',
			'background_color' => '#ffffff', 'theme_color' => self::color(), 'icons' => $icons];
		$sc = self::shortcuts();
		if ($sc) $m['shortcuts'] = $sc;
		return $m;
	}

	/** Up to four favourites of the signed-in user (links only – procedure tiles need a click on the page). @return list<array<string, string>> */
	public static function shortcuts(): array
	{
		try {
			if (!NuAuth::user()) return [];
			$out = [];
			foreach (NuSidebar::data(0)['fav'] as $it) {
				if (($it['post'] ?? null) !== null || !is_string($it['href'] ?? null) || $it['href'] === '' || preg_match('~^[a-z]+:~i', $it['href'])) continue;
				$out[] = ['name' => mb_substr(strip_tags((string)$it['label']), 0, 40), 'url' => (string)$it['href']];
				if (count($out) >= 4) break;
			}
			return $out;
		} catch (Throwable) {
			return [];
		}
	}

	/** PNG of the icon: the own logo on a white square when Branding uses it as browser icon, else the bundled one. */
	public static function icon(int $size, bool $mask): string
	{
		$size = in_array($size, self::SIZES, true) ? $size : 192;
		$b = NuBranding::get();
		if ($b['logo'] && $b['favicon'] && function_exists('imagecreatefromstring') && ($own = self::ownIcon($size, $mask)) !== null) return $own;
		$file = $mask ? ($size === 180 ? 'pwa-apple-180.png' : 'pwa-maskable-512.png') : ($size === 512 ? 'pwa-512.png' : 'pwa-192.png');
		return (string)file_get_contents(NU_ROOT . '/assets/img/' . $file);
	}

	private static function ownIcon(int $size, bool $mask): ?string
	{
		$raw = NuBranding::logoBytes();
		if ($raw === null || strlen($raw) > 5_000_000 || ($info = @getimagesizefromstring($raw)) === false || $info[0] * $info[1] > 25_000_000) return null;
		$src = @imagecreatefromstring($raw);
		if ($src === false) return null;
		$im = imagecreatetruecolor($size, $size);
		imagefill($im, 0, 0, (int)imagecolorallocate($im, 255, 255, 255));
		$box = (int)round($size * ($mask ? 0.62 : 0.86));      // maskable: the system cuts up to 20 % off every side
		$w = imagesx($src); $h = imagesy($src);
		$f = min($box / $w, $box / $h);
		$tw = max(1, (int)round($w * $f)); $th = max(1, (int)round($h * $f));
		imagecopyresampled($im, $src, (int)(($size - $tw) / 2), (int)(($size - $th) / 2), 0, 0, $tw, $th, $w, $h);
		ob_start(); imagepng($im, null, 9); $png = (string)ob_get_clean();
		return $png;
	}

	/** The service worker. */
	public static function worker(): string
	{
		$v = self::version();
		$offline = nu_url('pwa', ['a' => 'offline', 'v' => $v]);
		$pre = [$offline, nu_asset('assets/css/nuoffline.css'), nu_asset('assets/img/favicon.svg')];
		$on = self::enabled() ? 'true' : 'false';
		$cfg = json_encode(['v' => $v, 'offline' => $offline, 'pre' => $pre], JSON_UNESCAPED_SLASHES);
		return <<<JS
/* nuBuilder Next service worker – static files from the cache, pages always from the server */
'use strict';
var C = {$cfg}, ON = {$on}, NAME = 'nunext-' + C.v, BASE = self.registration.scope;
function abs(u) { return new URL(u, BASE).href; }
self.addEventListener('install', function (e) {
	if (!ON) { self.skipWaiting(); return; }
	e.waitUntil(caches.open(NAME).then(function (c) { return c.addAll(C.pre.map(abs)); }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
	e.waitUntil(caches.keys().then(function (ks) {
		return Promise.all(ks.filter(function (k) { return k.indexOf('nunext-') === 0 && (k !== NAME || !ON); }).map(function (k) { return caches.delete(k); }));
	}).then(function () { return ON ? self.clients.claim() : self.registration.unregister(); }));
});
// versioned static files of the package: assets/ and vendor/bundled/ with ?v=
function cacheable(u) {
	if (u.origin !== self.location.origin || u.href.indexOf(BASE) !== 0 || !u.searchParams.get('v')) return false;
	var p = u.pathname.substring(new URL(BASE).pathname.length);
	return /^(assets|vendor\/bundled)\//.test(p);
}
self.addEventListener('fetch', function (e) {
	if (!ON) return;
	var r = e.request;
	if (r.method !== 'GET') return;
	var u = new URL(r.url);
	if (r.mode === 'navigate' && u.href.indexOf(BASE) === 0) {
		e.respondWith(fetch(r).catch(function () {
			return caches.match(abs(C.offline)).then(function (m) { return m || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } }); });
		}));
		return;
	}
	if (!cacheable(u)) return;
	e.respondWith(caches.open(NAME).then(function (c) {
		return c.match(r).then(function (hit) {
			return hit || fetch(r).then(function (res) { if (res.ok && res.type === 'basic') c.put(r, res.clone()); return res; });
		});
	}));
});
JS;
	}

	public static function serve(string $a): never
	{
		session_write_close();
		header('X-Content-Type-Options: nosniff');
		if ($a === 'manifest') {
			header('Content-Type: application/manifest+json; charset=utf-8');
			header('Cache-Control: private, no-cache');
			echo json_encode(self::manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			exit;
		}
		if ($a === 'icon') {
			header('Content-Type: image/png');
			header('Cache-Control: public, max-age=604800');
			echo self::icon((int)($_GET['s'] ?? 192), ($_GET['m'] ?? '') === '1');
			exit;
		}
		if ($a === 'sw') {
			header('Content-Type: text/javascript; charset=utf-8');
			header('Cache-Control: no-cache');
			echo self::worker();
			exit;
		}
		if ($a === 'offline') {
			header('Content-Type: text/html; charset=utf-8');
			header('Cache-Control: no-cache');
			$title = h(self::name());
			echo '<!doctype html><html lang="' . h(nu_lang_choice()) . '"' . (nu_lang_rtl() ? ' dir="rtl"' : '') . '><head><meta charset="utf-8">'
				. '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h(t('pwa_offline_title')) . ' · ' . $title . '</title>'
				. '<link rel="stylesheet" href="' . nu_asset('assets/css/nuoffline.css') . '"><link rel="icon" href="' . nu_asset('assets/img/favicon.svg') . '" type="image/svg+xml"></head>'
				. '<body><main id="nuOffline"><img src="' . nu_asset('assets/img/favicon.svg') . '" alt="" width="56" height="56"><h1>' . h(t('pwa_offline_title')) . '</h1>'
				. '<p>' . h(t('pwa_offline_text')) . '</p><a class="nuOffBtn" href="">' . h(t('pwa_offline_retry')) . '</a><p class="nuOffApp">' . $title . '</p></main></body></html>';
			exit;
		}
		http_response_code(404);
		exit;
	}
}
