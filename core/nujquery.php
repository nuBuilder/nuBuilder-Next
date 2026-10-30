<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * jQuery of the form pages (0.8.741): the bundled 3.7.1 or 4.0.0, or a file the admin puts into custom/jquery/ (never touched by updates,
 * served through ?p=jqfile because custom/ is deny-all); jQuery Migrate (bundled 3.6.0 for jQuery 3, 4.0.2 for jQuery 4) off / warn (debug
 * build, messages collected per form through ?p=jqwarn) / restore (production build: removed functions back, no messages); a test for the
 * globeadmin only (own jQuery + warnings while users keep theirs). Setting row 'jquery'. Page ?p=jquery (settings centre → Code).
 * Static scan of the application's code: Compatibility check point J4 (scan()).
 */
final class NuJquery
{
	public const KEY = 'jquery';
	public const WARN_KEY = 'jquery_warnings';
	public const WARN_MAX = 300;                       // stored messages (oldest dropped)
	public const DEFAULTS = ['jq' => 'b3', 'migrate' => 'off', 'ga_jq' => '', 'ga_warn' => false];
	public const MODES = ['off', 'warn', 'restore'];
	public const BUNDLED = ['b3' => ['3.7.1', 'NU_JQUERY'], 'b4' => ['4.0.0', 'NU_JQUERY4']];
	public const MIGRATE = [3 => ['3.6.0', 'NU_JQMIGRATE3'], 4 => ['4.0.2', 'NU_JQMIGRATE4']];

	/** jQuery 4 removed these (Compatibility check J4) – name => replacement */
	public const REMOVED = ['trim' => 'String.prototype.trim()', 'isArray' => 'Array.isArray()', 'isFunction' => "typeof x === 'function'",
		'isNumeric' => 'Number.isFinite()', 'isWindow' => 'x != null && x === x.window', 'parseJSON' => 'JSON.parse()', 'type' => 'typeof / Array.isArray()',
		'now' => 'Date.now()', 'camelCase' => '-', 'nodeName' => 'el.nodeName.toLowerCase()', 'unique' => 'jQuery.uniqueSort()', 'cssProps' => '-',
		'cssNumber' => '-', 'proxy' => 'Function.prototype.bind()', 'holdReady' => '-'];

	private static ?array $cache = null;

	public static function dir(): string
	{
		return NU_ROOT . '/custom/jquery';
	}

	/** @return array{jq: string, migrate: string, ga_jq: string, ga_warn: bool} */
	public static function settings(): array
	{
		if (self::$cache !== null) return self::$cache;
		$s = [];
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::KEY]);
			$s = is_string($v) ? (json_decode($v, true) ?: []) : [];
		} catch (Throwable) { /* no settings table yet */ }
		return self::$cache = self::clean($s);
	}

	/** @param array<string, mixed> $s */
	public static function clean(array $s): array
	{
		$jq = static fn($v, bool $empty) => is_string($v) && (($empty && $v === '') || isset(self::BUNDLED[$v]) || (str_starts_with($v, 'c:') && self::validName(substr($v, 2)))) ? $v : ($empty ? '' : 'b3');
		return ['jq' => $jq($s['jq'] ?? 'b3', false), 'migrate' => in_array($s['migrate'] ?? '', self::MODES, true) ? (string)$s['migrate'] : 'off',
			'ga_jq' => $jq($s['ga_jq'] ?? '', true), 'ga_warn' => !empty($s['ga_warn'])];
	}

	public static function save(array $s): bool
	{
		$old = self::settings();
		$new = self::clean($s);
		if ($new === $old) return false;
		self::write(self::KEY, $new);
		self::$cache = $new;
		NuLog::write('jquery_changed', 'zzzzsys_nunext_setting', self::KEY, ['from' => $old, 'to' => $new]);
		return true;
	}

	private static function write(string $key, array $value): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$key])) {
			$db->update('zzzzsys_nunext_setting', ['setting_value' => $json], 'zzzzsys_nunext_setting_id', $key);
		} else {
			$db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $key, 'setting_value' => $json]);
		}
	}

	public static function validName(string $f): bool
	{
		return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.js$/', $f) && !str_contains($f, '..');
	}

	/** Files in custom/jquery/ with the jQuery version of their header. @return array<string, array{version: string, major: int, size: int, migrate: bool}> */
	public static function customFiles(): array
	{
		$out = [];
		foreach (glob(self::dir() . '/*.js') ?: [] as $p) {
			$f = basename($p);
			if (!self::validName($f) || !is_file($p)) continue;
			$head = (string)@file_get_contents($p, false, null, 0, 600);
			$mig = (bool)preg_match('/jQuery Migrate/i', $head);
			$ver = preg_match('/jQuery(?: JavaScript Library)? v?(\d+\.\d+\.\d+[\w.+-]*)/i', $head, $m) && !$mig ? $m[1] : '';
			$out[$f] = ['version' => $ver, 'major' => (int)$ver, 'size' => (int)filesize($p), 'migrate' => $mig];
		}
		ksort($out);
		return $out;
	}

	/** What a choice means: {choice, version, major, url, missing}. */
	public static function resolve(string $choice): array
	{
		if (isset(self::BUNDLED[$choice])) {
			[$v, $const] = self::BUNDLED[$choice];
			return ['choice' => $choice, 'version' => $v, 'major' => (int)$v, 'url' => nu_asset(constant($const) . '/jquery.min.js'), 'missing' => false];
		}
		$f = substr($choice, 2);
		$files = self::customFiles();
		if (!isset($files[$f]) || $files[$f]['migrate'] || $files[$f]['version'] === '') return ['missing' => true] + self::resolve('b3');
		return ['choice' => $choice, 'version' => $files[$f]['version'], 'major' => $files[$f]['major'],
			'url' => nu_url('jqfile', ['f' => $f, 'v' => base_convert((string)(int)@filemtime(self::dir() . '/' . $f), 10, 36)]), 'missing' => false];
	}

	/** Is the chosen custom file gone / not a jQuery file (then the bundled 3.7.1 is used)? */
	public static function missing(string $choice): bool
	{
		if (!str_starts_with($choice, 'c:')) return false;
		$files = self::customFiles();
		$f = substr($choice, 2);
		return !isset($files[$f]) || $files[$f]['migrate'] || $files[$f]['version'] === '';
	}

	/** For this request: {jq: resolve(), migrate: off|warn|restore, test: bool (the globeadmin's test)}. */
	public static function current(): array
	{
		$s = self::settings();
		$u = NuAuth::user();
		$ga = $u && !empty($u['globeadmin']);
		$test = $ga && ($s['ga_jq'] !== '' || $s['ga_warn']);
		$jq = self::resolve($test && $s['ga_jq'] !== '' ? $s['ga_jq'] : $s['jq']);
		$mode = $test && $s['ga_warn'] ? 'warn' : $s['migrate'];
		if (!isset(self::MIGRATE[$jq['major']])) $mode = 'off';                    // no Migrate for this major version
		return ['jq' => $jq, 'migrate' => $mode, 'test' => $test];
	}

	/** The script tags of the form page (in place of jquery.min.js). */
	public static function tags(string $formId): string
	{
		$c = self::current();
		$html = '<script src="' . h($c['jq']['url']) . '"></script>';
		if ($c['migrate'] !== 'off') {
			$const = self::MIGRATE[$c['jq']['major']][1];
			$html .= '<script src="' . h(nu_asset(constant($const) . '/' . ($c['migrate'] === 'warn' ? 'jquery-migrate.js' : 'jquery-migrate.min.js'))) . '"></script>';
			if ($c['migrate'] === 'warn') {
				$html .= '<script src="' . h(nu_asset('assets/js/nu/nujqmigrate.js')) . '" data-url="' . h(nu_url('jqwarn')) . '" data-csrf="' . h(nu_csrf_token())
					. '" data-form="' . h($formId) . '" data-jq="' . h($c['jq']['version']) . '"></script>';
			}
		}
		return $html;
	}

	/** Messages collected by nujqmigrate.js. @return array<string, array{f: string, m: string, n: int, first: string, last: string, jq: string}> */
	public static function warnings(): array
	{
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::WARN_KEY]);
			$w = is_string($v) ? json_decode($v, true) : [];
			return is_array($w) ? $w : [];
		} catch (Throwable) { return []; }
	}

	/** @param list<string> $messages */
	public static function addWarnings(string $formId, array $messages, string $jq): int
	{
		$w = self::warnings();
		$now = date('Y-m-d H:i:s');
		$n = 0;
		foreach (array_slice($messages, 0, 50) as $m) {
			if (!is_string($m) || trim($m) === '') continue;
			$m = mb_substr(trim((string)preg_replace('/\s+/', ' ', $m)), 0, 300);
			$k = substr(hash('sha256', $formId . '|' . $m), 0, 16);
			if (isset($w[$k])) { $w[$k]['n']++; $w[$k]['last'] = $now; $w[$k]['jq'] = $jq; }
			else $w[$k] = ['f' => $formId, 'm' => $m, 'n' => 1, 'first' => $now, 'last' => $now, 'jq' => $jq];
			$n++;
		}
		if (!$n) return 0;
		if (count($w) > self::WARN_MAX) {
			uasort($w, static fn($a, $b) => strcmp($b['last'], $a['last']));
			$w = array_slice($w, 0, self::WARN_MAX, true);
		}
		self::write(self::WARN_KEY, $w);
		return $n;
	}

	public static function clearWarnings(): void
	{
		self::write(self::WARN_KEY, []);
		NuLog::write('jquery_warnings_cleared', 'zzzzsys_nunext_setting', self::WARN_KEY, []);
	}

	/**
	 * Places in a piece of JavaScript that jQuery 4 breaks or Migrate warns about. @return list<string> distinct findings
	 */
	public static function scan(string $code): array
	{
		$out = [];
		$code = (string)preg_replace('~/\*.*?\*/|(?<![:\'"\\\\])//[^\n]*~s', ' ', $code);   // comments
		if (preg_match_all('/(?<![\w.])(?:\$|jQuery)\s*\.\s*(' . implode('|', array_keys(self::REMOVED)) . ')\s*\(/', $code, $m)) {
			foreach ($m[1] as $f) $out['$.' . $f . '()'] = true;
		}
		if (preg_match('/(?<![\w.])(?:\$|jQuery)\s*\.\s*fx\s*\.\s*interval\b/', $code)) $out['$.fx.interval'] = true;
		if (preg_match('/\.toggleClass\(\s*(?:\)|true\b|false\b)/', $code)) $out['.toggleClass() / .toggleClass(boolean)'] = true;
		if (preg_match_all('/\.(unbind|delegate|undelegate|andSelf|size)\(/', $code, $m)) foreach ($m[1] as $f) $out['.' . $f . '()'] = true;
		if (preg_match('/\.getStackHook\b/', $code)) $out['Deferred.getStackHook'] = true;
		return array_keys($out);
	}
}
