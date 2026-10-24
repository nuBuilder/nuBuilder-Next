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
	 * @return array<int, array{lang: string, where: string, form: string, code: string}>
	 */
	public static function sources(bool $withSystem): array
	{
		$db = nu_db();
		$sys = static fn(string $id) => !$withSystem && str_starts_with($id, 'nu');
		$out = [];
		$add = static function (string $lang, string $where, string $form, ?string $code) use (&$out) {
			if ($code !== null && trim($code) !== '') $out[] = ['lang' => $lang, 'where' => $where, 'form' => $form, 'code' => $code];
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
				$add('php', 'Procedure · ' . $p['sph_code'], (string)($p['sph_zzzzsys_form_id'] ?? ''), $p['sph_php']);
				if (isset($pc['sph_javascript'])) $add('js', 'Procedure JS · ' . $p['sph_code'], '', $p['sph_javascript']);
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
	public const SERVER_VARS = ['USER_ID', 'LOGIN_NAME', 'USER_LOGIN', 'USER_NAME', 'USER_EMAIL', 'SESSION_ID', 'GLOBAL_ACCESS', 'ACCESS_LEVEL_ID',
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
		foreach ((array)(nu_cfg('nuConfigIncludePHP') ?? []) as $file) {
			$file = (string)$file;
			if ($file !== '' && !str_starts_with($file, '/')) $file = NU_ROOT . '/' . $file;
			if (is_file($file) && preg_match_all('/function\s+(nu\w+)\s*\(/i', (string)file_get_contents($file), $m)) foreach ($m[1] as $n) $custom['php'][strtolower($n)] = true;
		}

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
				$r = $res[$lang][$name] ?? ['status' => $status, 'count' => 0, 'where' => [], 'forms' => []];
				$r['count']++;
				if (!in_array($s['where'], $r['where'], true)) $r['where'][] = $s['where'];
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
	 * nuBuilder 4.9 features of the compatibility report (0.8.382, ids C/M/N as there): where the application uses them, and since
	 * which version nuBuilder Next supports them (null = not yet – see the Help). SINCE is kept up to date with each fix.
	 */
	public const SINCE = ['C1' => '0.8.382', 'C2' => '0.8.382', 'C3' => '0.8.382', 'C4' => '0.8.382', 'C5' => '0.8.382', 'C6' => '0.8.382',
		'M1' => '0.8.383', 'M2' => '0.8.382', 'M3' => '0.8.382', 'M4' => null, 'M5' => '0.8.459', 'M6' => '0.8.443', 'M7' => '0.8.383', 'M8' => '0.8.383',
		'M9' => null, 'M10' => null, 'N1' => '0.8.383', 'N2' => '0.8.383', 'N3' => '0.8.383', 'I3' => null,
		// gap and migration analysis nuBuilder 4.9 / nuBuilder Next (0.8.462): G1 access data mode / form type (B3), G2 global hook
		// procedures (B5), G3 calc in sub-forms (B6), G4 events onnuload / onenter (B7), G5 nuFORMdata, G6 external scripts / frames
		// (the Content Security Policy blocks them), G7 hash variables nuBuilder Next does not set, G8 #RECORD_ID# / #FORM_ID# in hidden procedures
		'G1' => '0.8.461', 'G2' => '0.8.462', 'G3' => '0.8.462', 'G4' => '0.8.462', 'G5' => '0.8.462', 'G6' => null, 'G7' => null, 'G8' => null];

	/** nuBuilder's global hook procedures (by code) – G2. */
	public const HOOKS = ['nu_before_save', 'nu_before_edit', 'nu_before_delete', 'nu_startup', 'nu_invalid_login', 'nu_check_password_policy',
		'nu_after_update', 'nu_debug_result_added'];

	/** Hash variables of nuBuilder 4.9 that nuBuilder Next does not set – G7. */
	public const MISSING_HASH = ['USER_GROUP_ID', 'USER_A11Y', 'form_id', 'record_id', 'browse_sql', 'subforms'];

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
				if ($o['sob_all_type'] === 'html' && preg_match($ext, (string)($o['sob_html_code'] ?? ''), $m)) $hit('G6', $names[(string)$o['zzzzsys_object_id']][1], $fid, $m[3]);
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
	private static function stripComments(string $code): string
	{
		$code = (string)preg_replace('#/\*.*?\*/#s', ' ', $code);
		return (string)preg_replace('#(^|[^:\'"\\\\])//[^\n]*#', '$1', $code);
	}
}
