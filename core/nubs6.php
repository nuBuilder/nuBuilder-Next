<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Bootstrap 6 readiness (0.8.740, developer mode): finds markup and code that Bootstrap 6 changes – responsive / state classes with the
 * breakpoint inside the name (d-md-none → md:d-none in v6), offcanvas (→ drawer), the accordion (native <details>) and the global
 * window.bootstrap (gone: plugins are ES modules only). Used by the Compatibility check (application code, only in developer mode) and by
 * dev/bs6_scan.php (nuBuilder Next's own files). Only finds – nothing is changed. See TODO.md "Bootstrap 6".
 */
final class NuBs6
{
	/** rule => regular expression. 'responsive' = a breakpoint inside a class name, or the responsive forms col-md / navbar-expand-lg … */
	public const RULES = [
		'responsive' => '/(?<![\w-])(?:[a-z]+(?:-[a-z0-9]+)*-(?:sm|md|lg|xl|xxl)-[a-z0-9]+(?:-[a-z0-9]+)*|(?:col|navbar-expand|offcanvas|table-responsive|sticky|container|row-cols)-(?:sm|md|lg|xl|xxl))(?![\w-])/',
		'offcanvas'  => '/(?<![\w-])offcanvas(?:-[a-z]+)?(?![\w-])/',
		'accordion'  => '/(?<![\w-])accordion(?:-[a-z]+)?(?![\w-])/',
		'global'     => '/\bwindow\.bootstrap\b|(?<![\w.$])bootstrap\.(?:Modal|Tooltip|Popover|Dropdown|Collapse|Tab|Toast|Offcanvas|Alert|Button|Carousel|ScrollSpy)\b/',
	];

	/**
	 * Hits of one piece of code. @return array<string, list<string>> rule => distinct matches (in the order found)
	 */
	public static function scan(string $code): array
	{
		$out = [];
		foreach (self::RULES as $rule => $re) {
			if (!preg_match_all($re, $code, $m)) continue;
			$found = array_values(array_unique($m[0]));
			if ($rule === 'responsive') $found = array_values(array_filter($found, [self::class, 'isResponsive']));
			if ($found) $out[$rule] = $found;
		}
		return $out;
	}

	/** Not a responsive class: sizes and parts that only end with a breakpoint word (btn-sm, modal-lg, form-control-sm …) never reach here. */
	private static function isResponsive(string $c): bool
	{
		return !preg_match('/^(?:bi|fa|nu|text-bg|btn-outline|bg-opacity)-/', $c);   // icon names and own classes are not Bootstrap's
	}

	/**
	 * The application's code that may contain Bootstrap markup: JavaScript and HTML of forms, objects and events, procedures' JavaScript,
	 * form CSS, Setup header / style and Global code. @return list<array{where: string, form: string, hits: array<string, list<string>>}>
	 */
	public static function application(): array
	{
		$db = nu_db();
		$parts = [];
		foreach (NuCompat::sources(false) as $s) {
			// the scripts of HTML objects come again below with the whole HTML; Setup → Header comes with Setup → Style
			if ($s['lang'] !== 'js' || str_ends_with($s['where'], ' · HTML') || $s['where'] === 'Setup · Header') continue;
			$parts[] = [$s['where'], $s['form'], $s['code']];
		}
		if ($db->tableExists('zzzzsys_object')) {
			$oc = $db->columns('zzzzsys_object');
			if (isset($oc['sob_html_code'])) {
				foreach ($db->all("SELECT o.sob_all_id, o.sob_all_zzzzsys_form_id, o.sob_html_code, f.sfo_code FROM zzzzsys_object o LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
					WHERE o.sob_all_type = 'html' AND o.sob_all_zzzzsys_form_id NOT LIKE 'nu%'") as $o) {
					$parts[] = [($o['sfo_code'] ?? '?') . ' · ' . $o['sob_all_id'] . ' · HTML', (string)$o['sob_all_zzzzsys_form_id'], (string)$o['sob_html_code']];
				}
			}
		}
		$fc = $db->tableExists('zzzzsys_form') ? $db->columns('zzzzsys_form') : [];
		if (isset($fc['sfo_style'])) {
			foreach ($db->all("SELECT zzzzsys_form_id, sfo_code, sfo_style FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%'") as $f) $parts[] = [$f['sfo_code'] . ' · CSS', (string)$f['zzzzsys_form_id'], (string)$f['sfo_style']];
		}
		if ($db->tableExists('zzzzsys_setup')) {
			$sc = $db->columns('zzzzsys_setup');
			foreach (['set_style' => 'Style', 'set_header' => 'Header'] as $c => $l) if (isset($sc[$c])) $parts[] = ['Setup · ' . $l, '', (string)$db->value('SELECT ' . $c . ' FROM zzzzsys_setup')];
		}
		try {
			foreach (['custom_js' => 'Global JS', 'custom_css' => 'Global CSS'] as $k => $l) {
				$v = $db->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$k]);
				if (is_string($v)) $parts[] = ['Global code · ' . $l, '', $v];
			}
		} catch (Throwable) { /* no settings table */ }
		$out = [];
		foreach ($parts as [$where, $form, $code]) {
			if (trim($code) === '') continue;
			$hits = self::scan($code);
			if ($hits) $out[] = ['where' => (string)$where, 'form' => (string)$form, 'hits' => $hits];
		}
		return $out;
	}

	/**
	 * nuBuilder Next's own files (pages, core, assets/js, assets/css, apps; not vendor/). @return array{files: array<string, array<string, int>>, total: array<string, int>}
	 */
	public static function own(?string $root = null): array
	{
		$root ??= NU_ROOT;
		$files = [];
		$total = array_fill_keys(array_keys(self::RULES), 0);
		foreach (['pages', 'core', 'assets/js', 'assets/css', 'apps', 'index.php'] as $base) {
			$path = $root . '/' . $base;
			$list = is_file($path) ? [$path] : (is_dir($path) ? self::walk($path) : []);
			foreach ($list as $f) {
				if (!preg_match('/\.(php|js|css|htm|html)$/', $f) || str_ends_with($f, 'nubs6.php')) continue;
				$code = (string)@file_get_contents($f);
				foreach (self::RULES as $rule => $re) {
					$n = preg_match_all($re, $code, $m) ? count($rule === 'responsive' ? array_filter($m[0], [self::class, 'isResponsive']) : $m[0]) : 0;
					if ($rule === 'global' && str_ends_with($f, 'assets/js/nubs.js')) $n = 0;   // the wrapper: the one place that may use it
					if ($n) { $files[substr($f, strlen($root) + 1)][$rule] = $n; $total[$rule] += $n; }
				}
			}
		}
		ksort($files);
		return ['files' => $files, 'total' => $total];
	}

	/** @return list<string> */
	private static function walk(string $dir): array
	{
		$out = [];
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) if ($f->isFile()) $out[] = $f->getPathname();
		return $out;
	}
}
