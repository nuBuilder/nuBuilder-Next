<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Compatibility check (globeadmin, Admin tools): which nu… functions the application's code calls, and whether nuBuilder Next
 * supports them.
 *   JavaScript: form JS (sfo_javascript, browse / edit JS), object events (zzzzsys_event), lookup JS, HTML objects,
 *               setup header (set_header), procedure JS (sph_javascript).
 *   PHP:        procedures and hooks (zzzzsys_php), lookup PHP (sob_lookup_php).
 * Status per function: ok (implemented) · stub (exists, does nothing) · missing · custom (defined in your own code).
 * The list of supported JS functions is read from assets/js/nu/nuruntime.js, so it is always current.
 */
final class NuCompat
{
	/** name => 'ok' | 'stub' for the JavaScript runtime. */
	public static function jsSupport(): array
	{
		$out = [];
		// every script of our own a form page loads (pages/nuform.php), so functions outside the runtime count too –
		// e.g. nuNextDashboard / nuNextDashboardRefresh in assets/js/nudashboard.js (0.8.347)
		$files = ['assets/js/nu/nuruntime.js', 'assets/js/nu/nucompat.js', 'assets/js/nu/nuformat.js', 'assets/js/nu/nuinfobar.js', 'assets/js/nu/nutooltip.js',
			'assets/js/nuapp.js', 'assets/js/nudashboard.js'];                  // every page / forms with a dashboard (core/nudashboard.php)
		if (preg_match_all("~assets/js/(?:nu/)?[\\w-]+\\.js~", (string)@file_get_contents(NU_ROOT . '/pages/nuform.php'), $fm)) $files = array_values(array_unique(array_merge($files, $fm[0])));
		foreach ($files as $f) {
			$src = (string)@file_get_contents(NU_ROOT . '/' . $f);
			if (preg_match_all("/api\\('(nu\\w+)'/", $src, $m)) foreach ($m[1] as $n) $out[$n] = 'ok';
			if (preg_match_all('/window\.(nu\w+)\s*=\s*function/', $src, $m)) foreach ($m[1] as $n) $out[$n] = 'ok';
			if (preg_match_all('/\$\.fn\.(nu\w+)\s*=/', $src, $m)) foreach ($m[1] as $n) $out[$n] = 'ok';
			if (preg_match_all('/^\t\$\.(nu\w+)\s*=/m', $src, $m)) foreach ($m[1] as $n) $out[$n] = 'ok';                // $.nuInfoBar
			if (preg_match_all('/window\.(nu\w+)\s*=\s*\1\s*;/', $src, $m)) foreach ($m[1] as $n) $out[$n] = 'ok';        // window.nuAttachToolTip = nuAttachToolTip;
			if (preg_match_all("/proto\\(\\w+, '(nu\\w+)'/", $src, $m)) foreach ($m[1] as $n) $out[$n] = 'ok';            // String / Date methods
			if (preg_match_all('/(?:String|Date)\.prototype\.(nu\w+)\s*=/', $src, $m)) foreach ($m[1] as $n) $out[$n] = 'ok';
			// stubs: the list of names handed to the "not supported yet" helper
			if (preg_match('/\/\/ =+ features nuBuilder Next does not implement.*?\[(.*?)\]\.forEach/s', $src, $m) && preg_match_all("/'(nu\\w+)'/", $m[1], $s)) {
				foreach ($s[1] as $n) $out[$n] ??= 'stub';
			}
		}
		return $out;
	}

	/** nu… functions of the PHP API (lower-case names, PHP functions are case-insensitive). */
	public static function phpSupport(): array
	{
		require_once NU_ROOT . '/core/form/nuphp_api.php';
		$out = [];
		foreach (get_defined_functions()['user'] as $f) if (str_starts_with($f, 'nu') || str_starts_with($f, 'db_')) $out[$f] = 'ok';
		return $out;
	}

	/** Names of nuBuilder's own functions (from <nuBuilder>/core/*.js or *.php, when nuBuilder is found); PHP names lower-case. */
	public static function nuBuilderFunctions(string $lang): array
	{
		static $cache = [];
		if (isset($cache[$lang])) return $cache[$lang];
		$out = [];
		foreach (glob(NuBuilderLib::path() . '/core/*.' . ($lang === 'php' ? 'php' : 'js')) ?: [] as $f) {
			if (preg_match_all('/function\s+(nu\w+)\s*\(/', (string)file_get_contents($f), $m)) foreach ($m[1] as $n) $out[$lang === 'php' ? strtolower($n) : $n] = true;
		}
		return $cache[$lang] = $out;
	}

	/**
	 * All code of the application.
	 * @return array<int, array{lang: string, where: string, form: string, code: string, proc?: string}>
	 */
	public static function sources(bool $withSystem): array
	{
		$db = nu_db();
		$sys = static fn(string $id) => !$withSystem && str_starts_with($id, 'nu');
		$out = [];
		$add = static function (string $lang, string $where, string $form, ?string $code, string $proc = '') use (&$out) {
			if ($code !== null && trim($code) !== '') $out[] = ['lang' => $lang, 'where' => $where, 'form' => $form, 'code' => $code] + ($proc !== '' ? ['proc' => $proc] : []);
		};
		$cols = static fn(string $t) => $db->tableExists($t) ? $db->columns($t) : [];

		$fc = $cols('zzzzsys_form');
		foreach ($db->all('SELECT * FROM zzzzsys_form') as $f) {
			if ($sys((string)$f['zzzzsys_form_id'])) continue;
			foreach (['sfo_javascript' => 'JavaScript', 'sfo_browse_javascript' => 'Browse JS', 'sfo_edit_javascript' => 'Edit JS'] as $c => $lbl) {
				if (isset($fc[$c])) $add('js', $f['sfo_code'] . ' · ' . $lbl, (string)$f['zzzzsys_form_id'], $f[$c]);
			}
		}
		$oc = $cols('zzzzsys_object');
		$sel = ['o.zzzzsys_object_id', 'o.sob_all_id', 'o.sob_all_type', 'o.sob_all_zzzzsys_form_id', 'f.sfo_code'];
		foreach (['sob_lookup_javascript', 'sob_lookup_php', 'sob_html_code', 'sob_html_javascript'] as $c) if (isset($oc[$c])) $sel[] = 'o.' . $c;
		$objects = $db->all('SELECT ' . implode(', ', $sel) . ' FROM zzzzsys_object o LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id');
		$byId = [];
		foreach ($objects as $o) {
			$byId[(string)$o['zzzzsys_object_id']] = $o;
			if ($sys((string)$o['sob_all_zzzzsys_form_id'])) continue;
			$w = ($o['sfo_code'] ?? '?') . ' · ' . $o['sob_all_id'];
			$add('js', $w . ' · lookup JS', (string)$o['sob_all_zzzzsys_form_id'], $o['sob_lookup_javascript'] ?? null);
			$add('php', $w . ' · lookup PHP', (string)$o['sob_all_zzzzsys_form_id'], $o['sob_lookup_php'] ?? null);
			if ($o['sob_all_type'] === 'html') {
				$html = (string)($o['sob_html_code'] ?? '');
				if (preg_match_all('#<script[^>]*>(.*?)</script>#is', $html, $m)) $add('js', $w . ' · HTML', (string)$o['sob_all_zzzzsys_form_id'], implode("\n", $m[1]));
				if (preg_match_all('/\son\w+\s*=\s*"([^"]*)"/i', $html, $m)) $add('js', $w . ' · HTML', (string)$o['sob_all_zzzzsys_form_id'], implode("\n", $m[1]));
				$add('js', $w . ' · HTML JS', (string)$o['sob_all_zzzzsys_form_id'], $o['sob_html_javascript'] ?? null);
			}
		}
		if ($db->tableExists('zzzzsys_event')) {
			foreach ($db->all('SELECT sev_zzzzsys_object_id, sev_event, sev_javascript FROM zzzzsys_event') as $e) {
				$o = $byId[(string)$e['sev_zzzzsys_object_id']] ?? null;
				if ($o && $sys((string)$o['sob_all_zzzzsys_form_id'])) continue;
				$w = $o ? ($o['sfo_code'] ?? '?') . ' · ' . $o['sob_all_id'] : (string)$e['sev_zzzzsys_object_id'];
				$add('js', $w . ' · ' . $e['sev_event'], (string)($o['sob_all_zzzzsys_form_id'] ?? ''), $e['sev_javascript']);
			}
		}
		if ($db->tableExists('zzzzsys_setup')) $add('js', 'Setup · Header', '', (string)$db->value('SELECT set_header FROM zzzzsys_setup'));
		if ($db->tableExists('zzzzsys_php')) {
			$pc = $cols('zzzzsys_php');
			foreach ($db->all('SELECT * FROM zzzzsys_php') as $p) {
				if ($sys((string)$p['zzzzsys_php_id'])) continue;
				// a procedure (not a form's hook) can be opened from the report (0.8.693)
				$pid = in_array(substr((string)$p['zzzzsys_php_id'], -3), NuProcedure::HOOKS, true) ? '' : (string)$p['zzzzsys_php_id'];
				$add('php', 'Procedure · ' . $p['sph_code'], (string)($p['sph_zzzzsys_form_id'] ?? ''), $p['sph_php'], $pid);
				if (isset($pc['sph_javascript'])) $add('js', 'Procedure JS · ' . $p['sph_code'], '', $p['sph_javascript'], $pid);
			}
		}
		return $out;
	}

	/**
	 * Hash variables outside quotes in PHP and SQL (0.8.207): #field# is escaped for a quoted string only, so `WHERE id = #field#`
	 * or `$x = #field#;` lets a user put code into a field value. Variables the server sets (USER_ID, FORM_ID, TABLE_ID …) are fine;
	 * RECORD_ID, ID, CODE and SUBFORM_ID come from the browser and are listed too.
	 * @return array<int, array{lang: string, where: string, form: string, vars: array<int, string>}>
	 */
	public static function unquotedHash(bool $withSystem = false): array
	{
		$out = [];
		$sources = array_filter(self::sources($withSystem), static fn($s) => $s['lang'] === 'php');
		foreach (self::sqlSources($withSystem) as $s) $sources[] = $s;
		foreach ($sources as $s) {
			$vars = self::unquotedVars($s['code'], $s['lang']);
			if ($vars) $out[] = ['lang' => $s['lang'], 'where' => $s['where'], 'form' => $s['form'], 'vars' => $vars];
		}
		return $out;
	}

	/** SQL of the application: browse SQL, select / display SQL of objects, SQL builder queries. */
	public static function sqlSources(bool $withSystem): array
	{
		$db = nu_db();
		$out = [];
		$sys = static fn(string $id) => !$withSystem && str_starts_with($id, 'nu');
		$add = static function (string $where, string $form, ?string $code) use (&$out) {
			if ($code !== null && trim($code) !== '') $out[] = ['lang' => 'sql', 'where' => $where, 'form' => $form, 'code' => $code];
		};
		foreach ($db->all('SELECT zzzzsys_form_id, sfo_code, sfo_browse_sql FROM zzzzsys_form') as $f) {
			if (!$sys((string)$f['zzzzsys_form_id'])) $add($f['sfo_code'] . ' · Browse SQL', (string)$f['zzzzsys_form_id'], $f['sfo_browse_sql']);
		}
		$oc = $db->columns('zzzzsys_object');
		$cols = array_values(array_filter(['sob_select_sql', 'sob_display_sql'], static fn($c) => isset($oc[$c])));
		if ($cols) {
			foreach ($db->all('SELECT o.sob_all_id, o.sob_all_zzzzsys_form_id, f.sfo_code, ' . implode(', ', array_map(static fn($c) => 'o.' . $c, $cols))
				. ' FROM zzzzsys_object o LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id') as $o) {
				if ($sys((string)$o['sob_all_zzzzsys_form_id'])) continue;
				foreach ($cols as $c) $add(($o['sfo_code'] ?? '?') . ' · ' . $o['sob_all_id'] . ' · SQL', (string)$o['sob_all_zzzzsys_form_id'], $o[$c]);
			}
		}
		if ($db->tableExists('zzzzsys_select')) {
			foreach ($db->all('SELECT zzzzsys_select_id, sse_description, sse_sql FROM zzzzsys_select') as $q) {
				if (!$sys((string)$q['zzzzsys_select_id'])) $add('SQL builder · ' . $q['sse_description'], '', $q['sse_sql']);
			}
		}
		return $out;
	}

	/** Hash variables the server sets (NuHash::base() and the form's own) – safe without quotes; nu_date … too (prefix nu_). */
	public const SERVER_VARS = ['USER_ID', 'LOGIN_NAME', 'USER_LOGIN', 'USER_NAME', 'USER_EMAIL', 'SESSION_ID', 'GLOBAL_ACCESS', 'ACCESS_LEVEL_ID', 'USER_GROUP_ID',
		'ACCESS_LEVEL_CODE', 'ACCESS_LEVEL_GROUP', 'USER_LANGUAGE', 'USER_CODE', 'USER_DEPARTMENT', 'USER_TEAM', 'USER_POSITION', 'USER_FIRST_NAME',
		'USER_LAST_NAME', 'HOME_ID', 'LANGUAGE', 'DEV_MODE', 'USER_PERMISSIONS', 'DEPARTMENT', 'TEAM', 'POSITION', 'FIRST_NAME', 'LAST_NAME',
		'FORM_ID', 'FORM_CODE', 'FORM_GROUP', 'NEW_RECORD', 'TABLE_ID', 'PROCEDURE_CODE', 'OBJECT_ID'];

	/** Names of hash variables outside string quotes ('…', "…", `…`) and comments; server-set variables are left out. */
	public static function unquotedVars(string $code, string $lang): array
	{
		$safe = static fn(string $n): bool => in_array($n, self::SERVER_VARS, true) || str_starts_with($n, 'nu_');
		$found = [];
		$n = strlen($code);
		$q = '';                                          // open quote character
		$from = 0;                                        // start of the open string
		for ($i = 0; $i < $n; $i++) {
			$c = $code[$i];
			if ($q !== '') {
				if ($c === '\\' && $q !== '`') { $i++; continue; }
				if ($c === $q) {
					// PHP string with SQL in it (nuRunQuery("… WHERE id = #x#")): the PHP quotes do not protect the SQL
					$inner = substr($code, $from + 1, $i - $from - 1);
					if ($lang === 'php' && $q !== '`' && preg_match('/\b(SELECT|UPDATE|DELETE|INSERT|WHERE|FROM)\b/i', $inner)) {
						foreach (self::unquotedVars($inner, 'sql') as $v) $found[$v] = true;
					}
					$q = '';
				}
				continue;
			}
			if ($c === '#' && preg_match('/\G#([A-Za-z_][A-Za-z0-9_]*)#/', $code, $m, 0, $i)) {      // a hash variable comes before a # comment
				if (!$safe($m[1])) $found[$m[1]] = true;
				$i += strlen($m[0]) - 1;
				continue;
			}
			if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $from = $i; continue; }
			$next = $i + 1 < $n ? $code[$i + 1] : '';
			if (($c === '/' && $next === '/' && $lang === 'php') || ($c === '-' && $next === '-' && $lang === 'sql') || $c === '#') {
				$e = strpos($code, "\n", $i);
				$i = $e === false ? $n : $e;
				continue;
			}
			if ($c === '/' && $next === '*') {
				$e = strpos($code, '*/', $i + 2);
				$i = $e === false ? $n : $e + 1;
			}
		}
		return array_keys($found);
	}

	/**
	 * @return array{js: array, php: array} per language: name => [status, count, [where …], [form ids …]]
	 */
	public static function scan(bool $withSystem = false): array
	{
		$sources = self::sources($withSystem);
		$js = self::jsSupport();
		$php = self::phpSupport();

		// functions the application defines itself
		$custom = ['js' => [], 'php' => []];
		foreach ($sources as $s) {
			$code = self::stripComments($s['code']);
			if (preg_match_all('/function\s+(nu\w+)\s*\(|\b(nu\w+)\s*=\s*(?:async\s+)?(?:function\b|\([^)]*\)\s*=>)/', $code, $m)) {
				foreach (array_filter(array_merge($m[1], $m[2])) as $n) $custom[$s['lang']][$s['lang'] === 'php' ? strtolower($n) : $n] = true;
			}
		}
		// functions of the include files and the other page-wide code (0.8.578): $nuConfigIncludePHP / $nuConfigIncludeJS (found like the
		// form page finds them – nuBuilder Next's folder, then nuBuilder's), the scripts of Setup → Include and Global JS
		foreach (self::definedOutside() as $lang => $names) foreach ($names as $n) $custom[$lang][$lang === 'php' ? strtolower($n) : $n] = true;

		/** @var array<string, array<string, array{status: string, count: int, where: list<string>, forms: list<string>}>> $res */
		$res = ['js' => [], 'php' => []];
		foreach ($sources as $s) {
			$code = (string)preg_replace('/function\s+nu\w+\s*\(/i', 'function _def(', self::stripComments($s['code']));   // definitions are no calls
			// calls: nuXxx( – not a definition, not a method of another object (except jQuery plug-ins $(…).nuXxx)
			if (!preg_match_all('/(?<![\w$])(\.\s*)?(nu[A-Z]\w*)\s*\(/', $code, $m, PREG_SET_ORDER)) continue;
			foreach ($m as $hit) {
				$name = $hit[2];
				$isMethod = $hit[1] !== '';
				$lang = $s['lang'];
				// PHP code that writes JavaScript (nuAddJavascript("nuMessage(…)")): a JS function, not a PHP one
				if ($lang === 'php' && !isset($php[strtolower($name)]) && !isset($custom['php'][strtolower($name)]) && (isset($js[$name]) || isset(self::nuBuilderFunctions('js')[$name]))) $lang = 'js';
				$key = $lang === 'php' ? strtolower($name) : $name;
				if ($isMethod && $lang === 'js' && !isset($js[$name])) continue;   // someObject.nuSomething(): not ours
				$status = isset($custom[$lang][$key]) ? 'custom'
					: ($lang === 'js' ? ($js[$name] ?? 'missing') : ($php[$key] ?? 'missing'));
				$r = $res[$lang][$name] ?? ['status' => $status, 'count' => 0, 'where' => [], 'forms' => [], 'procs' => []];
				$r['count']++;
				if (!in_array($s['where'], $r['where'], true)) $r['where'][] = $s['where'];
				if (!empty($s['proc'])) $r['procs'][$s['where']] = $s['proc'];   // where → procedure id (link on the report, 0.8.693)
				if ($s['form'] !== '' && !in_array($s['form'], $r['forms'], true)) $r['forms'][] = $s['form'];
				$res[$lang][$name] = $r;
			}
		}
		$order = ['missing' => 0, 'stub' => 1, 'custom' => 2, 'ok' => 3];
		foreach ($res as &$list) {
			uksort($list, static fn($a, $b) => [$order[$list[$a]['status']], -$list[$a]['count'], $a] <=> [$order[$list[$b]['status']], -$list[$b]['count'], $b]);
		}
		unset($list);
		return $res;
	}

	/**
	 * G6 (0.8.580): does the Content Security Policy of form pages (pages/nuform.php) block this element's address? Images from https
	 * are allowed, scripts / stylesheets from sites named in $nuConfigIncludeJS / CSS or Setup → Include (NuIncludes::cspSources),
	 * everything from this server; frames and other hosts are blocked. A protocol-relative address (//host) takes the scheme of this server.
	 */
	public static function cspBlocked(string $tag, string $url): bool
	{
		$scheme = nu_https() ? 'https:' : 'http:';
		if (!preg_match('~^(https?:)?//([^/?#\s]+)~i', $url, $m)) return false;
		$origin = ($m[1] !== '' ? strtolower($m[1]) : $scheme) . '//' . strtolower($m[2]);
		$self = $scheme . '//' . strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
		if ($origin === $self) return false;
		if ($tag === 'img') return !str_starts_with($origin, 'https:');
		static $allowed = null;
		$allowed ??= array_map(static fn($x) => preg_split('/\s+/', trim($x), -1, PREG_SPLIT_NO_EMPTY), NuIncludes::cspSources());
		if ($tag === 'script') return !in_array($origin, $allowed['script'], true);
		if ($tag === 'link') return !in_array($origin, $allowed['style'], true);
		return true;                                          // iframe: only this server
	}

	/**
	 * nu… functions defined outside the forms and procedures: include files of nuconfig.php, Setup → Include scripts (inline and local
	 * files), Global JS. Files larger than 5 MB are skipped. @return array{js: list<string>, php: list<string>}
	 */
	public static function definedOutside(): array
	{
		$out = ['js' => [], 'php' => []];
		$scan = static function (string $lang, ?string $code) use (&$out): void {
			if ($code === null || $code === '') return;
			$re = $lang === 'php' ? '/function\s+(nu\w+)\s*\(/i' : '/function\s+(nu\w+)\s*\(|\b(nu\w+)\s*=\s*(?:async\s+)?(?:function\b|\([^)]*\)\s*=>|\w+\s*=>)/';
			if (preg_match_all($re, $code, $m)) foreach (array_filter(array_merge($m[1], $m[2] ?? [])) as $n) $out[$lang][] = $n;
		};
		$read = static fn(?string $f) => $f !== null && is_file($f) && (int)filesize($f) <= 5_000_000 ? (string)file_get_contents($f) : null;
		foreach (NuIncludes::entries('php') as $e) $scan('php', $read(NuIncludes::phpFile($e)));
		foreach (NuIncludes::entries('js') as $e) $scan('js', $read(NuIncludes::localFile($e)));
		foreach (NuIncludes::setupElements() as $el) {
			if ($el['tag'] !== 'script') continue;
			if ($el['url'] !== '') $scan('js', $read(NuIncludes::localFile($el['url'])));
			else $scan('js', (string)preg_replace('~^<script\b[^>]*>|</script\s*>$~i', '', $el['html']));
		}
		try { $scan('js', NuCustomCode::get()['js']); } catch (Throwable) { /* no settings table */ }
		$out['js'] = array_values(array_unique($out['js']));
		$out['php'] = array_values(array_unique($out['php']));
		return $out;
	}

	/**
	 * nuBuilder 4.9 features of the compatibility report (0.8.382, ids C/M/N as there): where the application uses them, and since
	 * which version nuBuilder Next supports them (null = not yet – see the Help). SINCE is kept up to date with each fix.
	 */
	public const SINCE = ['C1' => '0.8.382', 'C2' => '0.8.382', 'C3' => '0.8.382', 'C4' => '0.8.382', 'C5' => '0.8.382', 'C6' => '0.8.382',
		'M1' => '0.8.383', 'M2' => '0.8.382', 'M3' => '0.8.382', 'M4' => null, 'M5' => '0.8.459', 'M6' => '0.8.443', 'M7' => '0.8.383', 'M8' => '0.8.383',
		'M9' => null, 'M10' => '0.8.863', 'N1' => '0.8.383', 'N2' => '0.8.383', 'N3' => '0.8.383', 'I3' => null,
		// gap and migration analysis nuBuilder 4.9 / nuBuilder Next (0.8.462): G1 access data mode / form type (B3), G2 global hook
		// procedures (B5), G3 calc in sub-forms (B6), G4 events onnuload / onenter (B7), G5 nuFORMdata, G6 external scripts / frames
		// (the Content Security Policy blocks them), G7 hash variables nuBuilder Next does not set, G8 #RECORD_ID# / #FORM_ID# in hidden procedures
		'G1' => '0.8.461', 'G2' => '0.8.462', 'G3' => '0.8.462', 'G4' => '0.8.462', 'G5' => '0.8.462', 'G6' => null, 'G7' => null, 'G8' => '0.8.576',   // G8: set, with other values than nuBuilder's – listed to check comparisons
		// G9 (0.8.523): Global JS of Custom code defines nuBuilder hook functions (nuOnLoad …) – a form's own function replaces them
		'G9' => null,
		// G10 (0.8.585): users with additional access levels – nuBuilder 4.x gives them only the rights of the main level
		'G10' => '0.8.585',
		// G11 (0.8.983): form code that waits for the page to load (DOMContentLoaded, window load, readystatechange) – never runs in
		// nuBuilder Forte (form code runs after the page has loaded); nuBuilder Next drops such handlers too (nuNextLoadGuard). Places listed
		'G11' => '0.8.983',
		// FE (0.8.784): forms for everyone (NuFormEveryone) that access levels created in nuBuilder Forte do not have yet
		'FE' => '0.8.784',
		// BS6 (0.8.740, developer mode only): Bootstrap 5 markup that Bootstrap 6 changes (NuBs6) – nothing to do while nuBuilder Next stays on 5.3
		'BS6' => null,
		// J4 (0.8.741): jQuery functions jQuery 4 removed / Migrate warns about in the application's code (NuJquery::scan) – a preparation
		'J4' => null];

	/** Findings that are a preparation, not a missing feature (grey, not counted as open) – BS6. */
	public const PREP = ['BS6', 'J4'];

	/** G11: code that waits for the page to load – addEventListener('DOMContentLoaded' | 'readystatechange'), window load, onload =. */
	public const LOAD_WAIT = '/addEventListener\\s*\\(\\s*([\'"`])(DOMContentLoaded|readystatechange)\\1|\\b(window|self)\\s*\\.\\s*addEventListener\\s*\\(\\s*([\'"`])load\\4|\\b(window|document)\\s*\\.\\s*on(load|readystatechange)\\s*=[^=]|\\$\\s*\\(\\s*window\\s*\\)\\s*\\.\\s*(on\\s*\\(\\s*([\'"`])load\\8|load\\s*\\()/';

	/** nuBuilder's global hook procedures (by code) – G2. */
	public const HOOKS = ['nu_before_save', 'nu_before_edit', 'nu_before_delete', 'nu_startup', 'nu_invalid_login', 'nu_check_password_policy',
		'nu_after_update', 'nu_debug_result_added'];

	/** Hash variables of nuBuilder 4.9 that nuBuilder Next does not set – G7. */
	public const MISSING_HASH = ['browse_sql'];   // form_id / record_id / hash_record_id: set since 0.8.576, USER_GROUP_ID since 0.8.581, USER_A11Y / subforms … since 0.8.984

	/** @return array<string, list<array{where: string, form: string, detail: string}>> finding id => places (only ids with places) */
	public static function findings(bool $withSystem = false): array
	{
		$db = nu_db();
		$sys = static fn(string $id) => !$withSystem && str_starts_with($id, 'nu');
		$out = [];
		$hit = static function (string $id, string $where, string $form, string $detail = '') use (&$out): void {
			$out[$id][] = ['where' => $where, 'form' => $form, 'detail' => mb_substr(trim((string)preg_replace('/\s+/', ' ', $detail)), 0, 160)];
		};
		$forms = [];
		foreach ($db->all('SELECT * FROM zzzzsys_form') as $f) $forms[(string)$f['zzzzsys_form_id']] = $f;
		// browse lists: C1 table / alias prefix, C2 AS alias, C3 no primary key in the field list, C4 SELECT * over a join, C5 nu_filter_match
		if ($db->tableExists('zzzzsys_browse')) {
			foreach ($db->all('SELECT sbr_zzzzsys_form_id, sbr_display FROM zzzzsys_browse') as $b) {
				$fid = (string)$b['sbr_zzzzsys_form_id'];
				if ($sys($fid) || !isset($forms[$fid])) continue;
				$d = (string)$b['sbr_display'];
				$plain = (string)preg_replace("/'[^']*'|\"[^\"]*\"/", "''", $d);
				$w = $forms[$fid]['sfo_code'] . ' · ' . t('compat_f_browse');
				if (preg_match('/(?<![\w.])[A-Za-z_]\w*\.[A-Za-z_]/', $plain)) $hit('C1', $w, $fid, $d);
				if (preg_match('/\sAS\s/i', $plain)) $hit('C2', $w, $fid, $d);
			}
		}
		foreach ($forms as $fid => $f) {
			if ($sys($fid)) continue;
			$sql = trim((string)($f['sfo_browse_sql'] ?? ''));
			if ($sql === '') continue;
			$w = $f['sfo_code'] . ' · ' . t('compat_f_browse_sql');
			if (stripos($sql, 'nu_filter_match') !== false) $hit('C5', $w, $fid, 'nu_filter_match');
			$p = NuBrowse::splitSelect($sql);
			if ($p === null) continue;
			$pk = (string)($f['sfo_primary_key'] ?? '');
			$star = (bool)preg_match('/(^|,)\s*([\w`]+\.)?\*\s*(,|$)/', $p['fields']);
			if (!$star && $pk !== '' && stripos($p['fields'], $pk) === false) $hit('C3', $w, $fid, $p['fields']);
			if ($star && preg_match('/\bJOIN\b/i', $p['from'])) $hit('C4', $w, $fid, $p['from']);
		}
		// objects: C6 access condition, M1 script in HTML, M4 select from a procedure, M5 file into the file system
		if ($db->tableExists('zzzzsys_object')) {
			$oc = $db->columns('zzzzsys_object');
			$sel = ['zzzzsys_object_id', 'sob_all_id', 'sob_all_type', 'sob_all_zzzzsys_form_id', 'sob_all_access'];
			foreach (['sob_all_access_condition', 'sob_html_code', 'sob_select_procedure', 'sob_input_type', 'sob_input_file_target'] as $c) if (isset($oc[$c])) $sel[] = $c;
			foreach ($db->all('SELECT ' . implode(', ', $sel) . ' FROM zzzzsys_object') as $o) {
				$fid = (string)$o['sob_all_zzzzsys_form_id'];
				if ($sys($fid)) continue;
				$w = ($forms[$fid]['sfo_code'] ?? $fid) . ' · ' . $o['sob_all_id'];
				if ((string)$o['sob_all_access'] === '9') $hit('C6', $w, $fid, (string)($o['sob_all_access_condition'] ?? ''));
				if ($o['sob_all_type'] === 'html' && stripos((string)($o['sob_html_code'] ?? ''), '<script') !== false) $hit('M1', $w, $fid);
				if (trim((string)($o['sob_select_procedure'] ?? '')) !== '') $hit('M4', $w, $fid, (string)$o['sob_select_procedure']);
				if (($o['sob_input_type'] ?? '') === 'file' && (string)($o['sob_input_file_target'] ?? '') === '1') $hit('M5', $w, $fid);
			}
		}
		// G1: access levels that limit a form to browse / edit or to reading (data mode)
		if ($db->tableExists('zzzzsys_access_form')) {
			$ac = $db->columns('zzzzsys_access_form');
			$cols = array_values(array_filter(['slf_data_mode', 'slf_form_type'], static fn($c) => isset($ac[$c])));
			if ($cols) {
				$acc = [];
				foreach ($db->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access') as $a) $acc[(string)$a['zzzzsys_access_id']] = (string)$a['sal_code'];
				foreach ($db->all('SELECT slf_zzzzsys_access_id, slf_zzzzsys_form_id, ' . implode(', ', $cols) . ' FROM zzzzsys_access_form') as $r) {
					$fid = (string)$r['slf_zzzzsys_form_id'];
					$dm = (string)($r['slf_data_mode'] ?? ''); $ft = (string)($r['slf_form_type'] ?? '');
					if ($sys($fid) || ($dm !== '0' && $ft !== '0' && $ft !== '1')) continue;
					$hit('G1', ($acc[(string)$r['slf_zzzzsys_access_id']] ?? (string)$r['slf_zzzzsys_access_id']) . ' · ' . ($forms[$fid]['sfo_code'] ?? $fid), $fid,
						trim(($dm === '0' ? 'data mode 0 ' : '') . ($ft === '0' || $ft === '1' ? 'form type ' . $ft : '')));
				}
			}
		}
		// G2: global hook procedures, G8: hidden procedures that read #RECORD_ID# / #FORM_ID#
		if ($db->tableExists('zzzzsys_php')) {
			foreach ($db->all('SELECT zzzzsys_php_id, sph_code, sph_run, sph_php FROM zzzzsys_php') as $r) {
				if (in_array((string)$r['sph_code'], self::HOOKS, true)) $hit('G2', 'Procedure · ' . $r['sph_code'], '');
				if ($sys((string)$r['zzzzsys_php_id']) || (string)$r['sph_run'] !== 'hide') continue;
				if (preg_match('/#(RECORD_ID|FORM_ID)#/', (string)$r['sph_php'], $m)) $hit('G8', 'Procedure · ' . $r['sph_code'], '', $m[0]);
			}
		}
		// G3: calc objects on sub-forms, G4: events onnuload / onenter, G6: external scripts and frames in HTML objects
		if ($db->tableExists('zzzzsys_object')) {
			$ext = '#<(script|iframe|link|img)\b[^>]*\b(src|href)\s*=\s*["\']?((https?:)?//[^"\'\s>]+)#i';
			$names = [];
			foreach ($db->all('SELECT zzzzsys_object_id, sob_all_id, sob_all_type, sob_all_zzzzsys_form_id, sob_html_code FROM zzzzsys_object') as $o) {
				$fid = (string)$o['sob_all_zzzzsys_form_id'];
				$names[(string)$o['zzzzsys_object_id']] = [$fid, ($forms[$fid]['sfo_code'] ?? $fid) . ' · ' . $o['sob_all_id']];
				if ($sys($fid)) continue;
				if ($o['sob_all_type'] === 'calc' && (string)($forms[$fid]['sfo_type'] ?? '') === 'subform') $hit('G3', $names[(string)$o['zzzzsys_object_id']][1], $fid);
				if ($o['sob_all_type'] === 'html' && preg_match_all($ext, (string)($o['sob_html_code'] ?? ''), $mm, PREG_SET_ORDER)) {
					foreach ($mm as $m) if (self::cspBlocked(strtolower($m[1]), $m[3])) { $hit('G6', $names[(string)$o['zzzzsys_object_id']][1], $fid, $m[3]); break; }
				}
			}
			if ($db->tableExists('zzzzsys_event')) {
				foreach ($db->all("SELECT sev_zzzzsys_object_id, sev_event FROM zzzzsys_event WHERE sev_event IN ('onnuload', 'onenter')") as $e) {
					[$fid, $w] = $names[(string)$e['sev_zzzzsys_object_id']] ?? ['', (string)$e['sev_zzzzsys_object_id']];
					if (!$sys($fid)) $hit('G4', $w, $fid, (string)$e['sev_event']);
				}
			}
			if ($db->tableExists('zzzzsys_setup')) {
				$su = $db->one('SELECT * FROM zzzzsys_setup');
				foreach (['set_header' => 'Header', 'set_include' => 'Include'] as $c => $lbl) {
					if (preg_match($ext, (string)($su[$c] ?? ''), $m)) $hit('G6', 'Setup · ' . $lbl, '', $m[3]);
				}
			}
		}
		// JavaScript and PHP of the application
		$js = ['M2' => '/\bnuAddBrowseTitleSelect\s*\(/', 'M3' => '/\bmultiSelect\s*:\s*true/', 'M7' => '/\bnuAfterSave\b/',
			'M8' => '/\bnuFORM\s*\.\s*(getProperty|setProperty|subform|data|calc|scrollList|getTables|breadcrumbs|tableSchema)\b/', 'N1' => '/\bnuSERVERRESPONSE\b/',
			'N2' => '/\b(nuCloseAfterSaveGlobal|nuFormatValueCleared(Global)?|nuSubformFilterOnAddValues?|nuSubformOnFilterRows|nuBrowseNoSearchResults)\b/',
			'N3' => '/\b(nuAddActionCheckbox|nuGetStartingTab|nuSubformFilterArray|nuSubformFilterAddObject|nuDatalistShowAllOnArrowClick|nuSearchablePopupUtils)\b/'];
		foreach (self::sources($withSystem) as $src) {
			$code = self::stripComments($src['code']);
			if ($src['lang'] === 'js') { foreach ($js as $id => $re) if (preg_match($re, $code, $m)) $hit($id, $src['where'], $src['form'], $m[0]); }
			elseif (preg_match('/\b(nuRunReport|nuRunReportId|nuSavePDF|nuBuildTempTable)\s*\(/i', $code, $m)) $hit('M9', $src['where'], $src['form'], $m[1]);
			if ($src['lang'] === 'php' && preg_match('/\bnuFORMdata\b|\bnu(Get|Set)NuDataValue\s*\(/', $code, $m)) $hit('G5', $src['where'], $src['form'], $m[0]);
			if (preg_match('/#(' . implode('|', self::MISSING_HASH) . ')#/', $code, $m)) $hit('G7', $src['where'], $src['form'], $m[0]);
		}
		// G11: handlers that wait for the page to load, in form code (not Setup → Header: it is part of Forte's page and its handlers run)
		foreach (self::sources($withSystem) as $src) {
			if ($src['lang'] !== 'js' || str_starts_with($src['where'], 'Setup ·')) continue;
			if (preg_match(self::LOAD_WAIT, self::stripComments($src['code']), $m)) $hit('G11', $src['where'], $src['form'], $m[0]);
		}
		// G9: Global JS (Custom code → All pages) that defines nuBuilder's hook names
		try { foreach (NuCustomCode::hookClashes() as $n) $hit('G9', 'Custom code · Global JS', '', $n); } catch (Throwable) { /* no settings table */ }
		// G10: users with additional access levels (NuUserAccess)
		if (NuUserAccess::ready()) {
			foreach ($db->all('SELECT x.user_id, u.sus_login_name, a.sal_code FROM ' . NuUserAccess::TABLE . ' x LEFT JOIN zzzzsys_user u ON ' . $db->sameText('u.zzzzsys_user_id', 'x.user_id')
				. ' LEFT JOIN zzzzsys_access a ON ' . $db->sameText('a.zzzzsys_access_id', 'x.access_id') . ' ORDER BY u.sus_login_name') as $x) {
				$hit('G10', 'User · ' . ($x['sus_login_name'] ?? $x['user_id']), '', (string)($x['sal_code'] ?? ''));
			}
		}
		// FE: forms for everyone that some access levels miss (created in nuBuilder Forte) – the form's Access levels dialog adds them on Save
		try {
			$miss = NuFormEveryone::missing();
			if ($miss) {
				$codes = array_column($db->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access'), 'sal_code', 'zzzzsys_access_id');
				foreach ($miss as $fid => $lv) $hit('FE', 'Form · ' . ($forms[$fid]['sfo_code'] ?? $fid), $fid, implode(', ', array_map(static fn($a) => (string)($codes[$a] ?? $a), $lv)));
			}
		} catch (Throwable) { /* no settings table */ }
		// J4: jQuery 4 – removed functions and Migrate warnings in the application's JavaScript (always shown, grey)
		foreach (self::sources($withSystem) as $src) {
			if ($src['lang'] !== 'js') continue;
			$f = NuJquery::scan($src['code']);
			if ($f) $hit('J4', $src['where'], $src['form'], implode(' · ', $f));
		}
		// BS6: Bootstrap 5 classes / window.bootstrap in the application's code – a preparation, shown only in developer mode
		if (NuHash::devMode()) {
			try {
				foreach (NuBs6::application() as $b) {
					$d = [];
					foreach ($b['hits'] as $rule => $list) $d[] = $rule . ': ' . implode(' ', array_slice($list, 0, 8)) . (count($list) > 8 ? ' …' : '');
					$hit('BS6', $b['where'], $b['form'], implode(' · ', $d));
				}
			} catch (Throwable) { /* tables missing */ }
		}
		// nuconfig.php: M6 own JavaScript / CSS files, M10 SSO
		foreach (['nuConfigIncludeJS', 'nuConfigIncludeCSS'] as $v) if (!empty(nu_cfg($v))) $hit('M6', 'nuconfig.php · $' . $v, '', implode(', ', (array)nu_cfg($v)));
		if (in_array(strtolower((string)nu_cfg('nuConfigLogonMode')), ['sso', 'both'], true)) $hit('M10', 'nuconfig.php · $nuConfigLogonMode', '', (string)nu_cfg('nuConfigLogonMode'));
		// I3: MyISAM tables (no transactions)
		if ($db->type === 'mysql') {
			foreach ($db->all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE = 'MyISAM'") as $r) $hit('I3', (string)$r['t'], '');
		}
		uksort($out, static fn($a, $b) => array_search($a, array_keys(self::SINCE), true) <=> array_search($b, array_keys(self::SINCE), true));
		return $out;
	}

	/** Removes // and /* *\/ comments (roughly; strings containing // are rare in nu… calls). */
	/**
	 * MySQL-only SQL (0.8.791) – shown on the Compatibility check while nuBuilder Next runs on SQL Server: browse SQL, select / display
	 * SQL of objects, the SQL builder and SQL in the strings of procedures and PHP events. id => [what MySQL wrote, regex, what T-SQL uses].
	 * The regexes run on the SQL with its comments and string contents taken out (except the rules on quotes themselves).
	 */
	public const MSSQL_RULES = [
		'backtick'   => ['`name`', '/`/', '[name] or a plain name'],
		'dquote'     => ['"text" as a string', '/"[^"\n]*"/', '\'text\' – double quotes name columns in T-SQL'],
		'bslash'     => ['\\\' inside a string', "/\\\\'/", "'' (two single quotes)"],
		'limit'      => ['LIMIT n', '/\bLIMIT\s+(\d|#|:|\?|\$)/i', 'SELECT TOP (n) … / ORDER BY … OFFSET m ROWS FETCH NEXT n ROWS ONLY'],
		'ifnull'     => ['IFNULL()', '/\bIFNULL\s*\(/i', 'ISNULL() / COALESCE()'],
		'if'         => ['IF(cond, a, b)', '/(?<![\w.])IF\s*\(/i', 'IIF(cond, a, b) / CASE WHEN … THEN … ELSE … END'],
		'gconcat'    => ['GROUP_CONCAT()', '/\bGROUP_CONCAT\s*\(/i', 'STRING_AGG(x, \',\') (SQL Server 2017+)'],
		'now'        => ['NOW() / SYSDATE()', '/\b(NOW|SYSDATE)\s*\(/i', 'GETDATE() / SYSDATETIME()'],
		'curdate'    => ['CURDATE() / CURRENT_DATE', '/\bCURDATE\s*\(|\bCURRENT_DATE\b(?!\s*\()/i', 'CAST(GETDATE() AS date)'],
		'curtime'    => ['CURTIME()', '/\bCURTIME\s*\(/i', 'CAST(GETDATE() AS time)'],
		'datefmt'    => ['DATE_FORMAT()', '/\bDATE_FORMAT\s*\(/i', 'FORMAT(d, \'dd.MM.yyyy\') / CONVERT(varchar, d, 104)'],
		'strtodate'  => ['STR_TO_DATE()', '/\bSTR_TO_DATE\s*\(/i', 'CONVERT(date, s, 104) / TRY_CONVERT()'],
		'dateadd'    => ['DATE_ADD() / DATE_SUB() / INTERVAL', '/\b(DATE_ADD|DATE_SUB|ADDDATE|SUBDATE)\s*\(|\bINTERVAL\s+[-\d#(]/i', 'DATEADD(day, n, d)'],
		'datediff'   => ['DATEDIFF(a, b) – two arguments', '/\bDATEDIFF\s*\(\s*[^,()]+(\([^()]*\))?[^,()]*,\s*[^,()]+(\([^()]*\))?[^,()]*\)/i', 'DATEDIFF(day, b, a)'],
		'tsdiff'     => ['TIMESTAMPDIFF()', '/\bTIMESTAMPDIFF\s*\(/i', 'DATEDIFF(unit, a, b)'],
		'unixtime'   => ['UNIX_TIMESTAMP() / FROM_UNIXTIME()', '/\b(UNIX_TIMESTAMP|FROM_UNIXTIME)\s*\(/i', 'DATEDIFF_BIG(second, \'1970-01-01\', d) / DATEADD(second, n, \'1970-01-01\')'],
		'dateparts'  => ['YEAR() … are fine; DAYOFWEEK() / WEEK() / LAST_DAY() …', '/\b(DAYOFWEEK|DAYOFMONTH|DAYOFYEAR|WEEKDAY|WEEK|WEEKOFYEAR|LAST_DAY|MONTHNAME|DAYNAME|YEARWEEK|QUARTER)\s*\(/i', 'DATEPART(weekday, d) / DATENAME() / EOMONTH()'],
		'subindex'   => ['SUBSTRING_INDEX()', '/\bSUBSTRING_INDEX\s*\(/i', 'CHARINDEX() + LEFT() / SUBSTRING()'],
		'locate'     => ['LOCATE() / INSTR() / POSITION()', '/\b(LOCATE|INSTR|POSITION)\s*\(/i', 'CHARINDEX(needle, text)'],
		'lucase'     => ['LCASE() / UCASE()', '/\b(LCASE|UCASE)\s*\(/i', 'LOWER() / UPPER()'],
		'length'     => ['LENGTH() / CHAR_LENGTH()', '/\b(LENGTH|CHAR_LENGTH|CHARACTER_LENGTH|OCTET_LENGTH)\s*\(/i', 'LEN() / DATALENGTH()'],
		'mid'        => ['MID() / SUBSTR()', '/\b(MID|SUBSTR)\s*\(/i', 'SUBSTRING(text, start, length)'],
		'lpad'       => ['LPAD() / RPAD()', '/\b(LPAD|RPAD)\s*\(/i', 'RIGHT(REPLICATE(\'0\', n) + x, n)'],
		'regexp'     => ['REGEXP / RLIKE', '/\b(NOT\s+)?(REGEXP|RLIKE)\b/i', 'LIKE / PATINDEX() (SQL Server 2025: REGEXP_LIKE())'],
		'nullsafe'   => ['<=>', '/<=>/', '(a = b OR (a IS NULL AND b IS NULL)) / IS NOT DISTINCT FROM (2022+)'],
		'dupkey'     => ['ON DUPLICATE KEY UPDATE', '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', 'MERGE … WHEN MATCHED THEN UPDATE … WHEN NOT MATCHED THEN INSERT'],
		'insignore'  => ['INSERT IGNORE', '/\bINSERT\s+IGNORE\b/i', 'IF NOT EXISTS (SELECT …) INSERT … / MERGE'],
		'replace'    => ['REPLACE INTO', '/\bREPLACE\s+INTO\b/i', 'MERGE / DELETE + INSERT'],
		'lastid'     => ['LAST_INSERT_ID()', '/\bLAST_INSERT_ID\s*\(/i', 'SCOPE_IDENTITY() (nuBuilder\'s keys are text – rarely needed)'],
		'foundrows'  => ['SQL_CALC_FOUND_ROWS / FOUND_ROWS()', '/\bSQL_CALC_FOUND_ROWS\b|\bFOUND_ROWS\s*\(/i', 'COUNT(*) OVER () / a second COUNT(*)'],
		'castsigned' => ['CAST(x AS SIGNED / UNSIGNED / CHAR)', '/\bAS\s+(SIGNED|UNSIGNED)\b|\bAS\s+CHAR\s*\)/i', 'CAST(x AS INT / BIGINT / NVARCHAR(100))'],
		'boolean'    => ['TRUE / FALSE', '/(?<![\w.\'])\b(TRUE|FALSE)\b(?![\w(])/i', '1 / 0'],
		'dual'       => ['FROM DUAL', '/\bFROM\s+DUAL\b/i', 'leave FROM DUAL out'],
		'using'      => ['JOIN … USING (col)', '/\bJOIN\b[^;]*?\bUSING\s*\(/i', 'JOIN … ON a.col = b.col'],
		'ddl'        => ['ENGINE= / AUTO_INCREMENT / UNSIGNED', '/\bENGINE\s*=|\bAUTO_INCREMENT\b|\bINT(\(\d+\))?\s+UNSIGNED\b/i', 'IDENTITY(1,1), INT / BIGINT'],
		'limitdel'   => ['UPDATE / DELETE … LIMIT', '/\b(UPDATE|DELETE)\b[^;]*\bLIMIT\b/i', 'UPDATE TOP (n) … / DELETE TOP (n) …'],
		'ifexists'   => ['DROP / CREATE TABLE IF (NOT) EXISTS', '/\b(DROP|CREATE)\s+(TEMPORARY\s+)?TABLE\s+IF\s+(NOT\s+)?EXISTS\b/i', 'DROP TABLE IF EXISTS (2016+) / IF OBJECT_ID(\'t\') IS NULL CREATE TABLE …'],
		'temptable'  => ['CREATE TEMPORARY TABLE', '/\bCREATE\s+TEMPORARY\s+TABLE\b/i', 'CREATE TABLE #name (…) – or nuBuilder\'s ___nu… tables'],
	];

	/** SQL of the application with MySQL-only parts: [rule id => [{where, form, proc, detail}]]. $sources only for tests. */
	public static function mssqlScan(bool $withSystem = false, ?array $sources = null): array
	{
		if ($sources === null) {
			$sources = self::sqlSources($withSystem);
			foreach (self::sources($withSystem) as $src) {          // PHP: the strings that hold SQL (nuRunQuery("SELECT …"))
				if ($src['lang'] !== 'php') continue;
				foreach (self::phpSqlStrings((string)$src['code']) as $sql) $sources[] = ['lang' => 'sql', 'where' => $src['where'], 'form' => $src['form'], 'code' => $sql] + (isset($src['proc']) ? ['proc' => $src['proc']] : []);
			}
		}
		$out = [];
		foreach ($sources as $src) {
			$raw = self::sqlNoComments((string)$src['code']);
			$bare = self::sqlNoStrings($raw);
			foreach (self::MSSQL_RULES as $id => [, $re]) {
				$on = in_array($id, ['dquote', 'bslash'], true) ? $raw : $bare;
				if (in_array($id, ['dquote'], true)) $on = (string)preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $raw);   // "…" outside single-quoted strings
				if (!preg_match($re, $on, $m)) continue;
				$key = $src['where'];
				if (isset($out[$id][$key])) continue;                // once per place
				$out[$id][$key] = ['where' => $src['where'], 'form' => (string)$src['form'], 'proc' => (string)($src['proc'] ?? ''), 'detail' => mb_substr(trim((string)preg_replace('/\s+/', ' ', $m[0])), 0, 80)];
			}
		}
		return array_map('array_values', $out);
	}

	/** String literals of PHP code that look like SQL (a SELECT / INSERT / UPDATE / DELETE …). @return list<string> */
	public static function phpSqlStrings(string $php): array
	{
		$out = [];
		try { $toks = token_get_all('<?php ' . $php); } catch (Throwable) { return []; }
		$cur = null;
		foreach ($toks as $t) {
			if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
				$q = $t[1][0];
				$out[] = $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($t[1], 1, -1)) : stripcslashes(substr($t[1], 1, -1));
			} elseif (is_array($t) && $t[0] === T_ENCAPSED_AND_WHITESPACE) {
				$cur = ($cur ?? '') . $t[1];
			} elseif (!is_array($t) && $t === '"' || (is_array($t) && in_array($t[0], [T_START_HEREDOC, T_END_HEREDOC], true))) {
				if ($cur !== null) { $out[] = $cur; $cur = null; }
			} elseif ($cur !== null && is_array($t) && in_array($t[0], [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
				$cur .= ' :v ';                                     // a PHP variable inside the string – a value
			}
		}
		if ($cur !== null) $out[] = $cur;
		return array_values(array_filter($out, static fn($x) => strlen($x) > 10 && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER)\b/i', $x)));
	}

	private static function sqlNoComments(string $sql): string
	{
		$sql = (string)preg_replace('#/\*.*?\*/#s', ' ', $sql);
		return (string)preg_replace('/(--\s|#\s)[^\n]*/', ' ', $sql);
	}

	/** Contents of single-quoted strings removed ('' stays), so a word inside a text is no hit. */
	private static function sqlNoStrings(string $sql): string
	{
		return (string)preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql);
	}

	private static function stripComments(string $code): string
	{
		$code = (string)preg_replace('#/\*.*?\*/#s', ' ', $code);
		return (string)preg_replace('#(^|[^:\'"\\\\])//[^\n]*#', '$1', $code);
	}
}
