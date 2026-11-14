<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Code search (0.8.1172, the owner: A3 · B2 · C2 · D2 · E1 · F1 · G1 + G2): one search over the code of the application – page
 * ?p=codesearch (pages/nucodesearch.php, assets/js/nucodesearch.js), admin right 'codesearch' (or the globeadmin). Read only: nothing typed
 * here is ever run, nothing is changed.
 *
 * Sources (sources()): procedures (PHP / JavaScript), form events (BB … AD) and the After Browse PHP of objects, form JavaScript / Browse JS /
 * Edit JS / CSS / Browse SQL / list column SQL, validation rules, objects (event JavaScript, lookup JS / PHP, HTML code and JS, input JS,
 * select / display SQL, calc formula, access condition, run filter, style), SQL builder queries, header / style / include of the form pages and
 * Global JS / CSS, code snippets, e-mail templates, dashboard widget SQL, data export SQL, webhook templates, defaults of custom user fields,
 * the files of custom pages and – marked as drafts – the open form drafts. nuBuilder Forte's own rows (ids nu…, nx_…) only on request.
 *
 * Each source: place (PLACES), lang (LANGS), group (gkey / gtitle / gkind), label, code, url (where it is edited), ck = the textarea a line link
 * jumps to (?cl=<line>&ck=<key>&cq=<text> – assets/js/nu/nucodetools.js attach()), form / ref ids for the filters, sys, draft.
 */
final class NuCodeSearch
{
	public const LANGS = ['php', 'js', 'sql', 'css', 'html', 'text'];
	/** place => [label key, icon] – the order of the filter and of the parts inside a group */
	public const PLACES = [
		'proc'      => ['cs_pl_proc', 'bi-filetype-php'],
		'hook'      => ['cs_pl_hook', 'bi-lightning'],
		'formjs'    => ['cs_pl_formjs', 'bi-filetype-js'],
		'css'       => ['cs_pl_css', 'bi-filetype-css'],
		'browsesql' => ['cs_pl_browsesql', 'bi-filetype-sql'],
		'column'    => ['cs_pl_column', 'bi-layout-three-columns'],
		'rules'     => ['cs_pl_rules', 'bi-check2-square'],
		'event'     => ['cs_pl_event', 'bi-cursor'],
		'object'    => ['cs_pl_object', 'bi-ui-checks'],
		'objsql'    => ['cs_pl_objsql', 'bi-database'],
		'select'    => ['cs_pl_select', 'bi-diagram-3'],
		'global'    => ['cs_pl_global', 'bi-globe'],
		'snippet'   => ['cs_pl_snippet', 'bi-braces'],
		'email'     => ['cs_pl_email', 'bi-envelope'],
		'own'       => ['cs_pl_own', 'bi-gear'],
		'page'      => ['cs_pl_page', 'bi-file-earmark-code'],
	];
	/** group kinds => icon (order of the groups) */
	public const KINDS = ['form' => 'bi-window', 'proc' => 'bi-filetype-php', 'select' => 'bi-diagram-3', 'global' => 'bi-globe', 'snippet' => 'bi-braces',
		'email' => 'bi-envelope', 'dashboard' => 'bi-speedometer2', 'export' => 'bi-box-arrow-up', 'webhook' => 'bi-broadcast', 'userfield' => 'bi-person-vcard',
		'page' => 'bi-file-earmark-code'];
	public const HOOK_NAMES = ['BB' => 'builder_php_BB', 'BE' => 'builder_php_BE', 'BS' => 'builder_php_BS', 'AS' => 'builder_php_AS',
		'BD' => 'builder_php_BD', 'AD' => 'builder_php_AD', 'AB' => 'cs_hook_AB'];
	public const MAX_HITS = 2000;
	public const CONTEXT = 2;
	public const MAX_LINE = 300;
	public const MAX_FILE = 1048576;
	public const PAGE_EXT = ['php', 'js', 'mjs', 'css', 'html', 'htm', 'json', 'sql', 'md', 'txt', 'xml', 'ini'];
	public const KEY = 'codesearch';   // saved searches: zzzzsys_nunext_list_view list_key

	/** May the signed-in user search? The globeadmin or the admin right Code search; never in demo mode. */
	public static function allowed(): bool
	{
		$u = NuAuth::user();
		if (!$u || NuDemo::active()) return false;
		return !empty($u['globeadmin']) || NuAdmin::can('codesearch', 'view');
	}

	// ================================================================= the request

	/** The search of a request (GET or a saved search's query string). */
	public static function request(array $q): array
	{
		$arr = static fn(string $k): array => array_values(array_filter(array_map('strval', is_array($q[$k] ?? null) ? $q[$k] : (($q[$k] ?? '') !== '' ? [(string)$q[$k]] : [])), static fn($v) => $v !== ''));
		$go = isset($q['go']) || isset($q['q']);
		$m = (string)($q['m'] ?? 'phrase');
		return [
			'q' => mb_substr((string)($q['q'] ?? ''), 0, 1000),
			// a search the server builds (0.8.1173): 'missing' = calls of the functions nuBuilder Next lacks – one long regular expression,
			// too long for an address (nginx: 414 Request-URI Too Large)
			'preset' => ($q['preset'] ?? '') === 'missing' ? 'missing' : '',
			'm' => in_array($m, ['phrase', 'all', 'any'], true) ? $m : 'phrase',
			'x' => mb_substr((string)($q['x'] ?? ''), 0, 200),
			'w' => ($q['w'] ?? '') === '1', 'c' => ($q['c'] ?? '') === '1', 'r' => ($q['r'] ?? '') === '1',
			'lang' => array_values(array_intersect($arr('lang'), self::LANGS)),
			'place' => array_values(array_intersect($arr('place'), array_keys(self::PLACES))),
			'f' => array_slice($arr('f'), 0, 200),
			'grp' => mb_substr((string)($q['grp'] ?? ''), 0, 100), 'dep' => mb_substr((string)($q['dep'] ?? ''), 0, 100), 'team' => mb_substr((string)($q['team'] ?? ''), 0, 100),
			'prj' => mb_substr((string)($q['prj'] ?? ''), 0, 40),
			// sources: system rows off, drafts / page files on – unless the form was sent (then unticked = off)
			'sys' => ($q['sys'] ?? '') === '1',
			'drafts' => $go && isset($q['go']) ? ($q['drafts'] ?? '') === '1' : true,
			'pages' => $go && isset($q['go']) ? ($q['pages'] ?? '') === '1' : true,
		];
	}

	/** The query string of a search (links, export, saved searches) – only what differs from the defaults. */
	public static function query(array $s): array
	{
		$o = ['q' => $s['q'], 'go' => '1'] + ($s['preset'] !== '' ? ['preset' => $s['preset']] : []);
		if ($s['m'] !== 'phrase') $o['m'] = $s['m'];
		foreach (['x', 'grp', 'dep', 'team', 'prj'] as $k) if ($s[$k] !== '') $o[$k] = $s[$k];
		foreach (['w', 'c', 'r', 'sys', 'drafts', 'pages'] as $k) if ($s[$k]) $o[$k] = '1';
		foreach (['lang', 'place', 'f'] as $k) if ($s[$k]) $o[$k] = $s[$k];
		return $o;
	}

	// ================================================================= matching

	/**
	 * Patterns of a search: [terms, exclude, error]. Terms: the whole text (phrase), or its words / "quoted phrases" (all / any);
	 * a regular expression is always one pattern. Word = no letter, digit, _ or $ right before or after.
	 * @return array{0: list<string>, 1: ?string, 2: string}
	 */
	public static function patterns(array $s): array
	{
		if ($s['preset'] === 'missing') {
			$pp = self::presetPatterns();
			if (!$pp) return [[], null, ''];
			$x = trim($s['x']);
			return [[reset($pp)], $x !== '' ? '~' . preg_quote($x, '~') . '~ui' : null, ''];   // run() takes the pattern of each part's language
		}
		$q = trim($s['q']);
		if ($q === '') return [[], null, ''];
		$flags = 'u' . ($s['c'] ? '' : 'i');
		$wrap = static fn(string $p): string => $s['w'] ? '(?<![\p{L}\p{N}_$])(?:' . $p . ')(?![\p{L}\p{N}_$])' : $p;
		if ($s['r']) {
			$p = '~' . $wrap(str_replace('~', '\~', $q)) . '~' . $flags;
			if (@preg_match($p, '') === false) return [[], null, t('cs_err_regex')];
			$terms = [$p];
		} else {
			$words = [$q];
			if ($s['m'] !== 'phrase') {
				preg_match_all('/"([^"]+)"|(\S+)/u', $q, $mm, PREG_SET_ORDER);
				$words = array_values(array_unique(array_filter(array_map(static fn($x) => ($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''), $mm), static fn($w) => $w !== '')));
			}
			$terms = array_map(static fn($w) => '~' . $wrap(preg_quote($w, '~')) . '~' . $flags, $words);
		}
		$x = trim($s['x']);
		$ex = $x !== '' ? '~' . $wrap(preg_quote($x, '~')) . '~' . $flags : null;
		return [$terms, $ex, ''];
	}

	/** Does a source's code match (all / any of the terms, not the excluded text)? */
	public static function matches(string $code, array $terms, ?string $ex, string $mode): bool
	{
		if ($ex !== null && preg_match($ex, $code)) return false;
		$n = 0;
		foreach ($terms as $t) {
			if (preg_match($t, $code)) { $n++; if ($mode === 'any') return true; }
			elseif ($mode !== 'any') return false;
		}
		return $n > 0;
	}

	/**
	 * Hits of one source: blocks of lines (hit lines with CONTEXT lines around them, joined when they touch).
	 * @return array{hits: int, blocks: list<list<array{n: int, hit: bool, html: string, text: string}>>}
	 */
	public static function hits(string $code, array $terms, int $room): array
	{
		$lines = preg_split('/\R/u', $code) ?: [];
		$hit = [];
		foreach ($lines as $i => $line) {
			if (count($hit) >= $room) break;
			$ranges = [];
			foreach ($terms as $t) {
				if (!preg_match_all($t, $line, $mm, PREG_OFFSET_CAPTURE)) continue;
				foreach ($mm[0] as $m) if ($m[0] !== '') $ranges[] = [$m[1], $m[1] + strlen($m[0])];
			}
			if ($ranges) $hit[$i] = $ranges;
		}
		$blocks = [];
		$cur = [];
		$last = -10;
		foreach (array_keys($hit) as $i) {
			$from = max(0, $i - self::CONTEXT);
			if ($cur && $from > $last + 1) { $blocks[] = $cur; $cur = []; }
			for ($j = max($from, $last + 1); $j <= min(count($lines) - 1, $i + self::CONTEXT); $j++) {
				$cur[] = ['n' => $j + 1, 'hit' => isset($hit[$j]), 'html' => self::lineHtml((string)$lines[$j], $hit[$j] ?? []), 'text' => (string)$lines[$j]];
				$last = $j;
			}
		}
		if ($cur) $blocks[] = $cur;
		return ['hits' => count($hit), 'blocks' => $blocks];
	}

	/** One line as HTML: matches in <mark>, a long line cut around the first match. Byte ranges of the line. */
	public static function lineHtml(string $line, array $ranges): string
	{
		$line = rtrim($line, "\r");
		usort($ranges, static fn($a, $b) => $a[0] <=> $b[0]);
		$merged = [];
		foreach ($ranges as $r) {
			$k = count($merged) - 1;
			if ($k >= 0 && $r[0] <= $merged[$k][1]) $merged[$k][1] = max($merged[$k][1], $r[1]);
			else $merged[] = $r;
		}
		// the window of a long line: from a little before the first match
		$start = 0;
		$end = strlen($line);
		$pre = $post = '';
		if (mb_strlen($line) > self::MAX_LINE) {
			$first = $merged[0][0] ?? 0;
			$start = max(0, $first - 60);
			while ($start > 0 && (ord($line[$start]) & 0xC0) === 0x80) $start--;
			$end = $start + 4 * self::MAX_LINE;
			$cut = mb_strcut($line, $start, $end - $start);
			$cut = mb_substr($cut, 0, self::MAX_LINE);
			$end = $start + strlen($cut);
			if ($start > 0) $pre = '…';
			if ($end < strlen($line)) $post = '…';
		}
		$out = $pre;
		$pos = $start;
		foreach ($merged as [$a, $b]) {
			if ($b <= $start || $a >= $end) continue;
			$a = max($a, $start); $b = min($b, $end);
			$out .= h(substr($line, $pos, $a - $pos)) . '<mark>' . h(substr($line, $a, $b - $a)) . '</mark>';
			$pos = $b;
		}
		return $out . h(substr($line, $pos, $end - $pos)) . $post;
	}

	// ================================================================= the search

	/**
	 * Runs a search. @return array{groups: list<array>, sources: int, matched: int, hits: int, truncated: bool, error: string, ms: int}
	 */
	public static function run(array $s): array
	{
		$t0 = microtime(true);
		[$terms, $ex, $err] = self::patterns($s);
		$out = ['groups' => [], 'sources' => 0, 'matched' => 0, 'hits' => 0, 'truncated' => false, 'error' => $err, 'ms' => 0];
		if ($err !== '' || !$terms) return $out;
		$preset = $s['preset'] === 'missing' ? self::presetPatterns() : [];
		$sources = self::filter(self::sources($s), $s);
		$out['sources'] = count($sources);
		$groups = [];
		foreach ($sources as $src) {
			$t = $terms;
			if ($s['preset'] === 'missing') {                   // JavaScript names in JavaScript / HTML, PHP names in PHP (0.8.1176)
				$k = $src['lang'] === 'php' ? 'php' : (in_array($src['lang'], ['js', 'html'], true) ? 'js' : '');
				if ($k === '' || !isset($preset[$k])) continue;
				$t = [$preset[$k]];
			}
			if (!self::matches($src['code'], $t, $ex, $s['m'])) continue;
			$room = self::MAX_HITS - $out['hits'];
			if ($room <= 0) { $out['truncated'] = true; break; }
			$h = self::hits($src['code'], $t, $room);
			if (!$h['hits']) continue;                          // e.g. a term over two lines
			$out['hits'] += $h['hits'];
			$out['matched']++;
			if ($out['hits'] >= self::MAX_HITS) $out['truncated'] = true;
			$g = $src['gkey'];
			$groups[$g] ??= ['key' => $g, 'kind' => $src['gkind'], 'title' => $src['gtitle'], 'sub' => $src['gsub'] ?? '', 'url' => $src['gurl'] ?? $src['url'],
				'draft' => !empty($src['draft']), 'sys' => !empty($src['sys']), 'hits' => 0, 'parts' => []];
			$groups[$g]['hits'] += $h['hits'];
			$src['hash'] = sha1($src['code']);                  // replace: the code as shown (0.8.1174) – changed since = not replaced
			$src['key'] = !empty($src['rw']) ? self::key($src['rw']) : '';
			unset($src['code']);
			$groups[$g]['parts'][] = $src + $h;
		}
		$kinds = array_flip(array_keys(self::KINDS));
		$places = array_flip(array_keys(self::PLACES));
		foreach ($groups as &$g) usort($g['parts'], static fn($a, $b) => [$places[$a['place']] ?? 99, $a['label']] <=> [$places[$b['place']] ?? 99, $b['label']]);
		unset($g);
		usort($groups, static fn($a, $b) => [$kinds[$a['kind']] ?? 99, mb_strtolower($a['title']), $a['draft']] <=> [$kinds[$b['kind']] ?? 99, mb_strtolower($b['title']), $b['draft']]);
		$out['groups'] = $groups;
		$out['ms'] = (int)round((microtime(true) - $t0) * 1000);
		return $out;
	}

	/** The filters of a search on the sources (language, place, forms, group / department / team, project). */
	public static function filter(array $sources, array $s): array
	{
		$prj = null;
		if ($s['prj'] !== '' && class_exists('NuProject') && NuProject::ready()) {
			$prj = [];
			foreach (['form', 'procedure', 'select', 'emailtemplate', 'snippet'] as $k) $prj[$k] = array_flip(NuProject::refs($s['prj'], $k));
		}
		$byForm = $s['f'] || $s['grp'] !== '' || $s['dep'] !== '' || $s['team'] !== '';
		$fset = array_flip($s['f']);
		$lc = static fn(string $v): string => mb_strtolower(trim($v));
		return array_values(array_filter($sources, static function (array $x) use ($s, $prj, $byForm, $fset, $lc): bool {
			if ($s['lang'] && !in_array($x['lang'], $s['lang'], true)) return false;
			if ($s['place'] && !in_array($x['place'], $s['place'], true)) return false;
			if (!empty($x['sys']) && !$s['sys']) return false;
			if (!empty($x['draft']) && !$s['drafts']) return false;
			if ($x['place'] === 'page' && !$s['pages']) return false;
			$form = (string)($x['form'] ?? '');
			if ($byForm) {
				if ($form === '') return false;
				if ($fset && !isset($fset[$form])) return false;
				if ($s['grp'] !== '' && $lc((string)($x['fgroup'] ?? '')) !== $lc($s['grp'])) return false;
				if ($s['dep'] !== '' && $lc((string)($x['fdep'] ?? '')) !== $lc($s['dep'])) return false;
				if ($s['team'] !== '' && $lc((string)($x['fteam'] ?? '')) !== $lc($s['team'])) return false;
			}
			if ($prj !== null) {
				if ($form !== '') return isset($prj['form'][$form]);
				foreach (['proc' => 'procedure', 'sel' => 'select', 'email' => 'emailtemplate', 'snip' => 'snippet'] as $f => $k) {
					if (($x[$f] ?? '') !== '') return isset($prj[$k][(string)$x[$f]]);
				}
				return false;
			}
			return true;
		}));
	}

	// ================================================================= sources

	private static function isSys(string $id): bool { return str_starts_with($id, 'nu') || str_starts_with($id, 'nx_'); }

	/** Link to design mode of a form (through ?p=builder, NuDesign::fromBuilder). */
	public static function designUrl(string $formId, array $extra = []): string
	{
		return nu_url('builder', ['f' => $formId] + $extra);
	}

	/** All sources (unfiltered except the system / drafts / pages switches, which are applied in filter()). @return list<array> */
	public static function sources(array $s = []): array
	{
		$db = nu_db();
		$out = [];
		$colsOf = static fn(string $t): array => $db->tableExists($t) ? $db->columns($t) : [];
		$pick = static function (string $t, array $want) use ($colsOf): array {
			$c = $colsOf($t);
			return array_values(array_filter($want, static fn($x) => isset($c[$x])));
		};

		// ---- forms with their objects, events, list columns and hooks
		$forms = [];
		if ($db->tableExists('zzzzsys_form')) {
			$fc = $pick('zzzzsys_form', ['zzzzsys_form_id', 'sfo_code', 'sfo_description', 'sfo_group', 'sfo_type', 'sfo_javascript', 'sfo_browse_javascript', 'sfo_edit_javascript', 'sfo_style', 'sfo_browse_sql', 'sfo_json']);
			foreach ($db->all('SELECT ' . implode(', ', $fc) . ' FROM zzzzsys_form') as $f) $forms[(string)$f['zzzzsys_form_id']] = ['form' => $f, 'objects' => [], 'events' => [], 'browse' => [], 'php' => []];
		}
		$objForm = [];
		if ($forms && $db->tableExists('zzzzsys_object')) {
			foreach ($db->all('SELECT * FROM zzzzsys_object') as $o) {
				$fid = (string)$o['sob_all_zzzzsys_form_id'];
				$objForm[(string)$o['zzzzsys_object_id']] = $fid;
				if (isset($forms[$fid])) $forms[$fid]['objects'][] = $o;
			}
		}
		if ($forms && $db->tableExists('zzzzsys_event')) {
			foreach ($db->all('SELECT zzzzsys_event_id, sev_zzzzsys_object_id, sev_event, sev_javascript FROM zzzzsys_event') as $e) {
				$fid = $objForm[(string)$e['sev_zzzzsys_object_id']] ?? '';
				if (isset($forms[$fid])) $forms[$fid]['events'][] = $e;
			}
		}
		if ($forms && $db->tableExists('zzzzsys_browse')) {
			$bc = $pick('zzzzsys_browse', ['zzzzsys_browse_id', 'sbr_zzzzsys_form_id', 'sbr_title', 'sbr_display', 'sbr_order']);
			foreach ($db->all('SELECT ' . implode(', ', $bc) . ' FROM zzzzsys_browse') as $b) {
				$fid = (string)$b['sbr_zzzzsys_form_id'];
				if (isset($forms[$fid])) $forms[$fid]['browse'][] = $b;
			}
		}
		$procs = [];
		if ($db->tableExists('zzzzsys_php')) {
			$pc = $pick('zzzzsys_php', ['zzzzsys_php_id', 'sph_code', 'sph_description', 'sph_php', 'sph_javascript', 'sph_zzzzsys_form_id']);
			foreach ($db->all('SELECT ' . implode(', ', $pc) . ' FROM zzzzsys_php') as $p) {
				$id = (string)$p['zzzzsys_php_id'];
				$suf = substr($id, -3);
				$base = substr($id, 0, -3);
				if (in_array($suf, NuProcedure::HOOKS, true)) {
					$fid = isset($forms[$base]) ? $base : ($objForm[$base] ?? '');
					if ($fid !== '' && isset($forms[$fid])) { $forms[$fid]['php'][] = $p; continue; }
				}
				$procs[] = $p;
			}
		}
		foreach ($forms as $fid => $d) {
			foreach (self::formSources($d['form'], $d['objects'], $d['events'], $d['browse'], $d['php'], false) as $x) $out[] = $x;
		}
		// open drafts: their own copy of the form (only the parts that differ from the live form would be new – all are listed, marked)
		if (($s['drafts'] ?? true) && $db->tableExists('zzzzsys_nunext_form_draft')) {
			foreach ($db->all('SELECT form_id, snapshot FROM zzzzsys_nunext_form_draft WHERE status IN (?, ?)', [NuFormDraft::OPEN, NuFormDraft::REVIEW]) as $dr) {
				$snap = json_decode((string)$dr['snapshot'], true);
				if (!is_array($snap) || !is_array($snap['form'] ?? null)) continue;
				foreach (self::formSources($snap['form'], (array)($snap['objects'] ?? []), (array)($snap['events'] ?? []), (array)($snap['browse'] ?? []), (array)($snap['php'] ?? []), true) as $x) $out[] = $x;
			}
		}

		// ---- procedures
		foreach ($procs as $p) {
			$id = (string)$p['zzzzsys_php_id'];
			$code = (string)($p['sph_code'] ?? $id);
			$base = ['gkey' => 'p:' . $id, 'gkind' => 'proc', 'gtitle' => $code, 'gsub' => (string)($p['sph_description'] ?? ''), 'gurl' => nu_url('procedures', ['id' => $id]),
				'proc' => $id, 'sys' => self::isSys($id), 'form' => ''];
			$out[] = $base + ['place' => 'proc', 'lang' => 'php', 'label' => 'PHP', 'code' => (string)($p['sph_php'] ?? ''), 'url' => nu_url('procedures', ['id' => $id]), 'ck' => 'sph_php', 'rw' => ['zzzzsys_php', 'zzzzsys_php_id', $id, 'sph_php']];
			$out[] = $base + ['place' => 'proc', 'lang' => 'js', 'label' => 'JavaScript', 'code' => (string)($p['sph_javascript'] ?? ''), 'url' => nu_url('procedures', ['id' => $id]), 'ck' => 'sph_javascript', 'rw' => ['zzzzsys_php', 'zzzzsys_php_id', $id, 'sph_javascript']];
		}

		// ---- SQL builder
		if ($db->tableExists('zzzzsys_select')) {
			$sc = $pick('zzzzsys_select', ['zzzzsys_select_id', 'sse_code', 'sse_description', 'sse_sql']);
			foreach ($db->all('SELECT ' . implode(', ', $sc) . ' FROM zzzzsys_select') as $q) {
				$id = (string)$q['zzzzsys_select_id'];
				// the browse / select clauses of objects (<object id>_BR …) belong to their form – kept, but named by the query
				$title = trim((string)($q['sse_description'] ?? '')) !== '' ? (string)$q['sse_description'] : (string)($q['sse_code'] ?? $id);
				$out[] = ['gkey' => 's:' . $id, 'gkind' => 'select', 'gtitle' => $title, 'gsub' => (string)($q['sse_code'] ?? ''), 'place' => 'select', 'lang' => 'sql', 'label' => 'SQL',
					'code' => (string)($q['sse_sql'] ?? ''), 'url' => nu_url('sqlbuilder', ['id' => $id]), 'ck' => 'sse_sql', 'sel' => $id, 'sys' => self::isSys($id), 'form' => ''];
			}
		}

		// ---- header / style / include of the form pages, Global JS / CSS
		$gl = ['gkey' => 'g:global', 'gkind' => 'global', 'gtitle' => t('scode_title'), 'gurl' => nu_url('globalcode'), 'place' => 'global', 'form' => ''];
		if ($db->tableExists('zzzzsys_setup')) {
			$set = $pick('zzzzsys_setup', ['zzzzsys_setup_id', 'set_header', 'set_style', 'set_include']);
			$row = $set ? ($db->one('SELECT ' . implode(', ', $set) . ' FROM zzzzsys_setup') ?: []) : [];
			foreach (['set_header' => ['header', 'js', 'scode_tab_header'], 'set_style' => ['style', 'css', 'scode_tab_style'], 'set_include' => ['include', 'html', 'scode_tab_include']] as $c => [$tab, $lang, $lbl]) {
				if (isset($row[$c])) $out[] = $gl + ['lang' => $lang, 'label' => t($lbl), 'code' => (string)$row[$c], 'url' => nu_url('globalcode', ['tab' => $tab]), 'ck' => 'scode-' . $tab,
					'rw' => isset($row['zzzzsys_setup_id']) ? ['zzzzsys_setup', 'zzzzsys_setup_id', (string)$row['zzzzsys_setup_id'], $c] : null];
			}
		}
		$cc = NuCustomCode::get();
		$out[] = $gl + ['lang' => 'js', 'label' => t('scode_tab_gjs'), 'code' => (string)$cc['js'], 'url' => nu_url('globalcode', ['tab' => 'gjs']), 'ck' => 'scode-gjs', 'rw' => ['zzzzsys_nunext_setting', 'zzzzsys_nunext_setting_id', NuCustomCode::KEYS['js'], 'setting_value']];
		$out[] = $gl + ['lang' => 'css', 'label' => t('scode_tab_gcss'), 'code' => (string)$cc['css'], 'url' => nu_url('globalcode', ['tab' => 'gcss']), 'ck' => 'scode-gcss', 'rw' => ['zzzzsys_nunext_setting', 'zzzzsys_nunext_setting_id', NuCustomCode::KEYS['css'], 'setting_value']];

		// ---- code snippets
		if ($db->tableExists('zzzzsys_code_snippet')) {
			$nc = $pick('zzzzsys_code_snippet', ['zzzzsys_code_snippet_id', 'cot_code', 'cot_description', 'cot_source_code', 'cot_language']);
			foreach ($db->all('SELECT ' . implode(', ', $nc) . ' FROM zzzzsys_code_snippet') as $n) {
				$id = (string)$n['zzzzsys_code_snippet_id'];
				$l = strtolower((string)($n['cot_language'] ?? ''));
				$lang = str_contains($l, 'php') ? 'php' : (str_contains($l, 'sql') ? 'sql' : (str_contains($l, 'css') ? 'css' : (str_contains($l, 'html') ? 'html' : (str_contains($l, 'java') || $l === 'js' ? 'js' : 'text'))));
				$out[] = ['gkey' => 'n:' . $id, 'gkind' => 'snippet', 'gtitle' => (string)($n['cot_code'] ?? $id), 'gsub' => (string)($n['cot_description'] ?? ''), 'place' => 'snippet',
					'lang' => $lang, 'label' => t('cs_pl_snippet'), 'code' => (string)($n['cot_source_code'] ?? ''), 'url' => nu_url('form', ['f' => 'nucodesnippet', 'r' => $id]), 'ck' => 'cot_source_code',
					'rw' => ['zzzzsys_code_snippet', 'zzzzsys_code_snippet_id', $id, 'cot_source_code'], 'snip' => $id, 'sys' => self::isSys($id), 'form' => ''];
			}
		}

		// ---- e-mail templates
		if ($db->tableExists('zzzzsys_email_template')) {
			$ec = $pick('zzzzsys_email_template', ['zzzzsys_email_template_id', 'emt_code', 'emt_description', 'emt_subject', 'emt_body']);
			foreach ($db->all('SELECT ' . implode(', ', $ec) . ' FROM zzzzsys_email_template') as $e) {
				$id = (string)$e['zzzzsys_email_template_id'];
				$b = ['gkey' => 'e:' . $id, 'gkind' => 'email', 'gtitle' => (string)($e['emt_code'] ?? $id), 'gsub' => (string)($e['emt_description'] ?? ''), 'place' => 'email',
					'url' => nu_url('emailtemplates', ['id' => $id]), 'email' => $id, 'sys' => self::isSys($id), 'form' => ''];
				$out[] = $b + ['lang' => 'text', 'label' => t('cs_subject'), 'code' => (string)($e['emt_subject'] ?? ''), 'ck' => 'emt_subject', 'rw' => ['zzzzsys_email_template', 'zzzzsys_email_template_id', $id, 'emt_subject']];
				$out[] = $b + ['lang' => 'html', 'label' => t('cs_body'), 'code' => (string)($e['emt_body'] ?? ''), 'ck' => 'emt_body', 'rw' => ['zzzzsys_email_template', 'zzzzsys_email_template_id', $id, 'emt_body']];
			}
		}

		// ---- nuBuilder Next's own: dashboard widgets, data exports, webhooks, defaults of user fields
		if ($db->tableExists('zzzzsys_nunext_widget') && $db->tableExists('zzzzsys_nunext_dashboard')) {
			$titles = [];
			foreach ($db->all('SELECT zzzzsys_nunext_dashboard_id AS id, title, code FROM zzzzsys_nunext_dashboard') as $d) $titles[(string)$d['id']] = (string)($d['title'] ?: $d['code']);
			foreach ($db->all('SELECT zzzzsys_nunext_widget_id AS id, dashboard_id, title, source, query FROM zzzzsys_nunext_widget') as $w) {
				$did = (string)$w['dashboard_id'];
				$out[] = ['gkey' => 'd:' . $did, 'gkind' => 'dashboard', 'gtitle' => $titles[$did] ?? $did, 'gsub' => t('dash_title'), 'place' => 'own',
					'lang' => (string)$w['source'] === 'sql' ? 'sql' : 'text', 'label' => (string)($w['title'] ?? ''), 'code' => (string)($w['query'] ?? ''),
					'url' => nu_url('dashboards', ['id' => $did, 'w' => (string)$w['id']]), 'ck' => 'dSql', 'form' => '',
					'rw' => ['zzzzsys_nunext_widget', 'zzzzsys_nunext_widget_id', (string)$w['id'], 'query']];
			}
		}
		if ($db->tableExists('zzzzsys_nunext_export')) {
			foreach ($db->all('SELECT zzzzsys_nunext_export_id AS id, code, name, datasets FROM zzzzsys_nunext_export') as $x) {
				$sqls = [];
				self::walkSql(json_decode((string)$x['datasets'], true), $sqls);
				foreach ($sqls as $i => $sql) {
					$out[] = ['gkey' => 'x:' . $x['id'], 'gkind' => 'export', 'gtitle' => (string)($x['name'] ?: $x['code']), 'gsub' => t('dxp_title'), 'place' => 'own', 'lang' => 'sql',
						'label' => 'SQL ' . ($i + 1), 'code' => $sql, 'url' => nu_url('dataexport', ['id' => (string)$x['id']]), 'ck' => '', 'form' => ''];
				}
			}
		}
		if ($db->tableExists('zzzzsys_nunext_webhook')) {
			foreach ($db->all('SELECT zzzzsys_nunext_webhook_id AS id, name, template FROM zzzzsys_nunext_webhook') as $w) {
				$out[] = ['gkey' => 'w:' . $w['id'], 'gkind' => 'webhook', 'gtitle' => (string)$w['name'], 'gsub' => t('wh_title'), 'place' => 'own', 'lang' => 'text',
					'label' => t('cs_template'), 'code' => (string)($w['template'] ?? ''), 'url' => nu_url('webhooks', ['id' => (string)$w['id']]), 'ck' => 'whTemplate', 'form' => '',
					'rw' => ['zzzzsys_nunext_webhook', 'zzzzsys_nunext_webhook_id', (string)$w['id'], 'template']];
			}
		}
		if ($db->tableExists('zzzzsys_nunext_user_field')) {
			foreach ($db->all('SELECT zzzzsys_nunext_user_field_id AS id, code, label, default_value FROM zzzzsys_nunext_user_field') as $u) {
				$dv = (string)($u['default_value'] ?? '');
				$out[] = ['gkey' => 'u:' . $u['id'], 'gkind' => 'userfield', 'gtitle' => (string)($u['label'] ?: $u['code']), 'gsub' => t('uf_title'), 'place' => 'own',
					'lang' => preg_match('/^\s*=?\s*SELECT\b/i', $dv) ? 'sql' : 'text', 'label' => t('cs_default'), 'code' => $dv,
					'url' => nu_url('userfields', ['id' => (string)$u['id']]), 'ck' => '', 'form' => '',
					'rw' => ['zzzzsys_nunext_user_field', 'zzzzsys_nunext_user_field_id', (string)$u['id'], 'default_value']];
			}
		}

		// ---- files of custom pages
		if ($s['pages'] ?? true) {
			foreach ($forms as $fid => $d) {
				if ((string)($d['form']['sfo_type'] ?? '') !== 'page') continue;
				foreach (self::pageSources((string)$fid, (string)$d['form']['sfo_code'], (string)($d['form']['sfo_description'] ?? '')) as $x) $out[] = $x;
			}
		}
		return array_values(array_filter($out, static fn($x) => trim((string)$x['code']) !== ''));
	}

	/** SQL strings in a JSON structure (keys sql / query). */
	private static function walkSql(mixed $v, array &$out): void
	{
		if (!is_array($v)) return;
		foreach ($v as $k => $x) {
			if (is_string($x) && in_array(strtolower((string)$k), ['sql', 'query'], true) && trim($x) !== '') $out[] = $x;
			elseif (is_array($x)) self::walkSql($x, $out);
		}
	}

	/** The parts of one form (live rows or a draft's snapshot). @return list<array> */
	public static function formSources(array $f, array $objects, array $events, array $browse, array $php, bool $draft): array
	{
		$fid = (string)$f['zzzzsys_form_id'];
		$tags = NuFormMeta::tagsOf(isset($f['sfo_json']) ? (string)$f['sfo_json'] : null);
		$g = ['gkey' => 'f:' . $fid . ($draft ? ':d' : ''), 'gkind' => 'form', 'gtitle' => (string)($f['sfo_code'] ?? $fid), 'gsub' => trim(strip_tags((string)($f['sfo_description'] ?? ''))),
			'gurl' => self::designUrl($fid), 'form' => $fid, 'fgroup' => (string)($f['sfo_group'] ?? ''), 'fdep' => $tags['department'] ?? '', 'fteam' => $tags['team'] ?? '',
			'sys' => self::isSys($fid), 'draft' => $draft];
		$out = [];
		$code = static fn(string $item): string => self::designUrl($fid, ['open' => 'code', 'dsitem' => $item]);
		// replaceable (0.8.1174): the live rows only – a draft's copy is changed in design mode
		$rw = static fn(string $t, string $pk, string $id, string $c): ?array => !$draft && $id !== '' ? [$t, $pk, $id, $c] : null;
		foreach (['sfo_javascript' => ['formjs', 'js', 'builder_js_both'], 'sfo_browse_javascript' => ['formjs', 'js', 'builder_js_browse'], 'sfo_edit_javascript' => ['formjs', 'js', 'builder_js_edit'],
			'sfo_style' => ['css', 'css', 'builder_css'], 'sfo_browse_sql' => ['browsesql', 'sql', 'builder_browse_sql']] as $c => [$place, $lang, $lbl]) {
			if (isset($f[$c])) $out[] = $g + ['place' => $place, 'lang' => $lang, 'label' => t($lbl), 'code' => (string)$f[$c], 'url' => $code($c), 'ck' => $c, 'rw' => $rw('zzzzsys_form', 'zzzzsys_form_id', $fid, $c)];
		}
		usort($browse, static fn($a, $b) => (int)($a['sbr_order'] ?? 0) <=> (int)($b['sbr_order'] ?? 0));
		foreach ($browse as $b) {
			$out[] = $g + ['place' => 'column', 'lang' => 'sql', 'label' => t('cs_column') . ' ' . trim(strip_tags((string)($b['sbr_title'] ?? ''))), 'code' => (string)($b['sbr_display'] ?? ''),
				'url' => self::designUrl($fid, ['open' => 'list']), 'ck' => '', 'rw' => $rw('zzzzsys_browse', 'zzzzsys_browse_id', (string)($b['zzzzsys_browse_id'] ?? ''), 'sbr_display')];
		}
		// validation rules (sfo_json nunext.rules): one line per rule
		$rules = (array)(NuFormMeta::nunextJson(isset($f['sfo_json']) ? (string)$f['sfo_json'] : null)['rules'] ?? []);
		$rl = [];
		foreach ($rules as $r) {
			if (!is_array($r)) continue;
			$when = is_array($r['when'] ?? null) ? NuFormRules::text($r['when']) : '';
			$check = is_array($r['check'] ?? null) ? NuFormRules::text($r['check']) : '';
			$rl[] = trim((string)($r['name'] ?? '')) . ': ' . ($when !== '' ? 'IF ' . $when . ' ' : '') . 'CHECK ' . $check . ' → ' . (string)($r['msg'] ?? '');
		}
		if ($rl) $out[] = $g + ['place' => 'rules', 'lang' => 'text', 'label' => t('cs_pl_rules'), 'code' => implode("\n", $rl), 'url' => self::designUrl($fid, ['open' => 'rules']), 'ck' => ''];
		$objs = [];
		foreach ($objects as $o) $objs[(string)$o['zzzzsys_object_id']] = $o;
		foreach ($php as $p) {
			$id = (string)$p['zzzzsys_php_id'];
			$hook = substr($id, -2);
			$base = substr($id, 0, -3);
			if ($base === $fid && $hook !== 'AB') {
				$out[] = $g + ['place' => 'hook', 'lang' => 'php', 'label' => t(self::HOOK_NAMES[$hook] ?? 'cs_pl_hook'), 'code' => (string)($p['sph_php'] ?? ''), 'url' => $code('php:' . $hook), 'ck' => 'php:' . $hook, 'rw' => $rw('zzzzsys_php', 'zzzzsys_php_id', $id, 'sph_php')];
			} elseif (isset($objs[$base])) {
				$out[] = $g + ['place' => 'hook', 'lang' => 'php', 'label' => (string)$objs[$base]['sob_all_id'] . ' · ' . t('cs_hook_AB'), 'code' => (string)($p['sph_php'] ?? ''),
					'url' => self::designUrl($fid, ['obj' => $base]), 'ck' => '', 'rw' => $rw('zzzzsys_php', 'zzzzsys_php_id', $id, 'sph_php')];
			}
		}
		$fields = ['sob_input_javascript' => ['object', 'js'], 'sob_lookup_javascript' => ['object', 'js'], 'sob_lookup_php' => ['object', 'php'],
			'sob_html_code' => ['object', 'html'], 'sob_html_javascript' => ['object', 'js'], 'sob_calc_formula' => ['object', 'js'], 'sob_run_filter' => ['object', 'text'],
			'sob_all_style' => ['object', 'css'], 'sob_select_sql' => ['objsql', 'sql'], 'sob_display_sql' => ['objsql', 'sql'], 'sob_all_access_condition' => ['objsql', 'sql']];
		foreach ($objects as $o) {
			$oid = (string)$o['zzzzsys_object_id'];
			foreach ($fields as $c => [$place, $lang]) {
				if (!isset($o[$c]) || trim((string)$o[$c]) === '') continue;
				$out[] = $g + ['place' => $place, 'lang' => $lang, 'label' => (string)$o['sob_all_id'] . ' · ' . t('cs_f_' . $c), 'code' => (string)$o[$c],
					'url' => self::designUrl($fid, ['obj' => $oid]), 'ck' => '', 'rw' => $rw('zzzzsys_object', 'zzzzsys_object_id', $oid, $c)];
			}
		}
		foreach ($events as $e) {
			$o = $objs[(string)$e['sev_zzzzsys_object_id']] ?? null;
			if (!$o) continue;
			$out[] = $g + ['place' => 'event', 'lang' => 'js', 'label' => (string)$o['sob_all_id'] . ' · ' . (string)$e['sev_event'], 'code' => (string)($e['sev_javascript'] ?? ''),
				'url' => self::designUrl($fid, ['obj' => (string)$o['zzzzsys_object_id']]), 'ck' => '', 'rw' => $rw('zzzzsys_event', 'zzzzsys_event_id', (string)($e['zzzzsys_event_id'] ?? ''), 'sev_javascript')];
		}
		return $out;
	}

	/** Text files of a custom page's folder (not _data, _draft, _versions, secrets.php, dot names; ≤ MAX_FILE). @return list<array> */
	public static function pageSources(string $fid, string $code, string $desc): array
	{
		if (!class_exists('NuPage') || !NuPage::validCode($code)) return [];
		$dir = NuPage::folder($code);
		if (!is_dir($dir)) return [];
		$out = [];
		$ext = ['php' => 'php', 'js' => 'js', 'mjs' => 'js', 'css' => 'css', 'html' => 'html', 'htm' => 'html', 'sql' => 'sql'];
		foreach (array_keys(NuPageVersion::files($dir)) as $rel) {
			$e = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
			if (!in_array($e, self::PAGE_EXT, true)) continue;
			$p = $dir . '/' . $rel;
			if (!is_file($p) || filesize($p) > self::MAX_FILE) continue;
			$txt = (string)@file_get_contents($p);
			if ($txt === '' || !mb_check_encoding($txt, 'UTF-8')) continue;
			$out[] = ['gkey' => 'pg:' . $fid, 'gkind' => 'page', 'gtitle' => $code, 'gsub' => trim(strip_tags($desc)), 'gurl' => nu_url('builder', ['f' => $fid]),
				'place' => 'page', 'lang' => $ext[$e] ?? 'text', 'label' => $rel, 'code' => $txt, 'url' => nu_url('builder', ['f' => $fid]), 'ck' => '', 'form' => $fid, 'sys' => false];
		}
		return $out;
	}

	// ================================================================= quick searches

	/**
	 * Quick searches (F1): functions of the reference, tables, hash variables, Forte functions nuBuilder Next has not got.
	 * @return array{fn: list<array{0: string, 1: string}>, tables: list<string>, hash: list<string>, missing: list<string>}
	 */
	public static function quick(): array
	{
		$fn = [];
		foreach (['php', 'js'] as $l) foreach (NuFnRef::documented($l) as $n) $fn[] = [$n, $l];
		usort($fn, static fn($a, $b) => strcasecmp($a[0], $b[0]));
		$tables = [];
		try { $tables = array_values(array_filter(nu_db()->tableNames(), static fn($t) => !str_starts_with(strtolower($t), 'zzzzsys_'))); sort($tables); } catch (Throwable) {}
		$hash = [];
		foreach (NuHash::CATALOG as $names) foreach ($names as $n) $hash[] = '#' . $n . '#';
		sort($hash);
		return ['fn' => $fn, 'tables' => $tables, 'hash' => array_values(array_unique($hash)), 'missing' => self::missingFunctions()];
	}

	/** nuBuilder Forte functions listed as not ported (docs/pages/12-02-porting.htm, the JavaScript and PHP lists). @return list<string> */
	/**
	 * The patterns of "Functions nuBuilder Next lacks": lang => pattern of a call – the name and "(", not after "function " (a declaration of
	 * the form's own, e.g. a hook of the same name), ".", "->" or "::" (a method of an object). PHP names ignore case, as PHP does.
	 * @return array<string, string>
	 */
	public static function presetPatterns(): array
	{
		$out = [];
		foreach (['js' => 'u', 'php' => 'ui'] as $l => $fl) {
			$names = self::missingFunctions($l);
			if (!$names) continue;
			$out[$l] = '~(?<![\p{L}\p{N}_$.>:])(?<!function )(?<!function\t)(?:' . implode('|', array_map(static fn($n) => preg_quote($n, '~'), $names)) . ')\s*\(~' . $fl;
		}
		return $out;
	}

	public static function missingFunctions(string $lang = ''): array
	{
		$f = NU_ROOT . '/docs/pages/12-02-porting.htm';
		if (!is_file($f)) return [];
		$h = (string)file_get_contents($f);
		$i = strpos($h, 'id="porting-missing"');
		if ($i === false) return [];
		$h = substr($h, $i);
		$j = strpos($h, '</div>');
		if ($j !== false) $h = substr($h, 0, $j);
		// the JavaScript lists come before the heading "PHP (…)", the PHP lists after it
		$k = strpos($h, '>PHP (');
		if ($lang === 'js' && $k !== false) $h = substr($h, 0, $k);
		if ($lang === 'php') $h = $k !== false ? substr($h, $k) : '';
		// only the list paragraphs (<p><code>…) – the explanations above them also name functions that work (0.8.1175: nuSetFormValue)
		$out = [];
		preg_match_all('~<p>\s*(<code>.*?)</p>~s', $h, $paras);
		foreach ($paras[1] as $para) {
			preg_match_all('~<code>(nu[A-Za-z0-9_]+)</code>~', $para, $m);
			array_push($out, ...$m[1]);
		}
		// a function nuBuilder Next has (not as a placeholder) is never listed – in case a list and the code differ
		$have = array_map('strtolower', $lang === '' ? array_merge(NuFnRef::documented('php'), NuFnRef::documented('js')) : NuFnRef::documented($lang));
		if ($lang === 'php') $have = array_merge($have, array_map('strtolower', NuFnRef::codeNames('php')));   // PHP: names ignore case
		$out = array_values(array_filter(array_unique($out), static fn($n) => !in_array(strtolower($n), $have, true)));
		sort($out);
		return $out;
	}

	// ================================================================= saved searches

	private static function me(): string
	{
		$u = NuAuth::user();
		return !empty($u['globeadmin']) ? 'globeadmin' : (string)($u['id'] ?? '');
	}

	/** @return list<array{id: string, name: string, q: string}> */
	public static function saved(): array
	{
		$db = nu_db();
		if (!$db->tableExists(NuListView::TABLE)) return [];
		$out = [];
		foreach ($db->all('SELECT zzzzsys_nunext_list_view_id AS id, name, definition FROM ' . NuListView::TABLE . " WHERE list_key = ? AND scope = 'user' AND owner = ? ORDER BY name",
			[self::KEY, self::me()]) as $r) $out[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'q' => (string)$r['definition']];
		return $out;
	}

	public static function save(string $name, array $s): string
	{
		$name = mb_substr(trim($name), 0, 100);
		if ($name === '') return t('as_err_name');
		if (trim($s['q']) === '' && $s['preset'] === '') return t('cs_err_empty');
		NuSchema::ensure();
		$db = nu_db();
		$row = ['definition' => http_build_query(self::query($s)), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => self::me()];
		$id = $db->value('SELECT zzzzsys_nunext_list_view_id FROM ' . NuListView::TABLE . " WHERE list_key = ? AND scope = 'user' AND owner = ? AND name = ?", [self::KEY, self::me(), $name]);
		if ($id) $db->update(NuListView::TABLE, $row, 'zzzzsys_nunext_list_view_id', (string)$id);
		else $db->insert(NuListView::TABLE, ['zzzzsys_nunext_list_view_id' => nu_id(), 'list_key' => self::KEY, 'scope' => 'user', 'owner' => self::me(), 'name' => $name,
			'access_ids' => '[]', 'sort_order' => 0] + $row);
		return '';
	}

	public static function delete(string $id): void
	{
		nu_db()->run('DELETE FROM ' . NuListView::TABLE . " WHERE zzzzsys_nunext_list_view_id = ? AND list_key = ? AND scope = 'user' AND owner = ?", [$id, self::KEY, self::me()]);
	}

	// ================================================================= search and replace (E2, 0.8.1174)

	public const KEEP_BATCHES = 100;
	public const T = 'zzzzsys_nunext_code_replace';

	/** Replace is for the globeadmin only (never in demo mode). */
	public static function canReplace(): bool
	{
		$u = NuAuth::user();
		return $u && !empty($u['globeadmin']) && !NuDemo::active();
	}

	/** Key of a writable place: table | key column | id | column. */
	public static function key(array $rw): string
	{
		return substr(sha1(implode('|', $rw)), 0, 20);
	}

	/** Forms with an open draft (their live code is not replaced – publishing would bring the draft's code back). @return array<string, true> */
	public static function draftForms(): array
	{
		$db = nu_db();
		if (!$db->tableExists('zzzzsys_nunext_form_draft')) return [];
		return array_fill_keys(array_map('strval', array_column($db->all('SELECT DISTINCT form_id FROM zzzzsys_nunext_form_draft WHERE status IN (?, ?)', [NuFormDraft::OPEN, NuFormDraft::REVIEW]), 'form_id')), true);
	}

	/** Why a part cannot be replaced here ('' = it can). */
	public static function blockReason(array $part, array $drafts): string
	{
		if (empty($part['rw'])) return t('cs_rp_no_place');
		if (!empty($part['sys'])) return t('cs_rp_sys');
		if (($part['form'] ?? '') !== '' && isset($drafts[(string)$part['form']]) && ($part['place'] ?? '') !== 'page') return t('cs_rp_draft');
		return '';
	}

	/**
	 * Pattern and replacement: [pattern, replacement, error]. Only exact text or a regular expression (one pattern); plain text is
	 * inserted as typed, a regular expression may use $1 … of its groups.
	 * @return array{0: string, 1: string, 2: string}
	 */
	public static function replacePattern(array $s, string $rep): array
	{
		if ($s['preset'] !== '' || (!$s['r'] && $s['m'] !== 'phrase')) return ['', '', t('cs_rp_err_mode')];
		[$terms, , $err] = self::patterns($s);
		if ($err !== '') return ['', '', $err];
		if (!$terms) return ['', '', t('cs_err_empty')];
		return [$terms[0], $s['r'] ? $rep : str_replace(['\\', '$'], ['\\\\', '\\$'], $rep), ''];
	}

	/** The code with the given lines (1-based) replaced; line ends kept. @return array{0: string, 1: int} new code, lines changed */
	public static function replaceLines(string $code, string $pattern, string $rep, array $lines): array
	{
		$want = array_fill_keys(array_map('intval', $lines), true);
		$parts = preg_split('/(\r\n|\n|\r)/', $code, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$code];
		$n = 0;
		foreach ($parts as $i => $p) {
			if ($i % 2 || !isset($want[intdiv($i, 2) + 1])) continue;
			$new = preg_replace($pattern, $rep, $p);
			if (is_string($new) && $new !== $p) { $parts[$i] = $new; $n++; }
		}
		return [implode('', $parts), $n];
	}

	/** One line as it will read after the replace (preview). */
	public static function previewLine(string $line, string $pattern, string $rep): string
	{
		$new = @preg_replace($pattern, $rep, $line);
		return is_string($new) ? $new : $line;
	}

	private static function phpOk(string $code): bool
	{
		try { token_get_all('<?php ' . $code, TOKEN_PARSE); return true; } catch (Throwable) { return false; }
	}

	/**
	 * Replaces in the ticked lines. $ticks: key => list of line numbers, $hashes: key => sha1 of the code shown in the preview.
	 * Forms get a history snapshot first; every change is kept for undo(). @return array{ok: bool, error: string, parts: int, lines: int, skipped: list<string>, batch: string}
	 */
	public static function apply(array $s, string $rep, array $ticks, array $hashes): array
	{
		$out = ['ok' => false, 'error' => '', 'parts' => 0, 'lines' => 0, 'skipped' => [], 'batch' => ''];
		if (!self::canReplace()) { $out['error'] = t('err_forbidden'); return $out; }
		[$pattern, $repl, $err] = self::replacePattern($s, $rep);
		if ($err !== '') { $out['error'] = $err; return $out; }
		$ticks = array_filter(array_map(static fn($l) => array_values(array_filter(array_map('intval', (array)$l), static fn($n) => $n > 0)), $ticks));
		if (!$ticks) { $out['error'] = t('cs_rp_none_ticked'); return $out; }
		$drafts = self::draftForms();
		$byKey = [];
		foreach (self::sources(['drafts' => false, 'pages' => false]) as $src) if (!empty($src['rw'])) $byKey[self::key($src['rw'])] = $src;
		$db = nu_db();
		NuSchema::ensure();
		$changes = [];
		$forms = [];
		$plan = [];
		foreach ($ticks as $key => $lines) {
			$src = $byKey[(string)$key] ?? null;
			if (!$src) { $out['skipped'][] = (string)$key . ': ' . t('cs_rp_changed'); continue; }
			$name = $src['gtitle'] . ' · ' . $src['label'];
			$why = self::blockReason($src, $drafts);
			if ($why !== '') { $out['skipped'][] = $name . ': ' . $why; continue; }
			[$tb, $pk, $id, $col] = $src['rw'];
			$cur = $db->value('SELECT ' . NuDB::ident($col) . ' FROM ' . NuDB::ident($tb) . ' WHERE ' . NuDB::ident($pk) . ' = ?', [$id]);
			$cur = $cur === null ? '' : (string)$cur;
			if (!isset($hashes[$key]) || !hash_equals(sha1($cur), (string)$hashes[$key])) { $out['skipped'][] = $name . ': ' . t('cs_rp_changed'); continue; }
			[$new, $n] = self::replaceLines($cur, $pattern, $repl, $lines);
			if (!$n) continue;
			if ($src['lang'] === 'php' && self::phpOk($cur) && !self::phpOk($new)) { $out['skipped'][] = $name . ': ' . t('cs_rp_php'); continue; }
			$plan[] = [$src, $cur, $new, $n];
			if (($src['form'] ?? '') !== '') $forms[(string)$src['form']] = true;
		}
		if (!$plan) { $out['error'] = $out['skipped'] ? '' : t('cs_none'); $out['ok'] = !$out['skipped']; return $out; }
		foreach (array_keys($forms) as $fid) NuFormHistory::snapshot((string)$fid, 'code search: replace');   // the version before, restorable in design mode → History
		$db->transaction(static function (NuDB $db) use ($plan, &$changes, &$out): void {
			foreach ($plan as [$src, $cur, $new, $n]) {
				[$tb, $pk, $id, $col] = $src['rw'];
				$db->run('UPDATE ' . NuDB::ident($tb) . ' SET ' . NuDB::ident($col) . ' = ? WHERE ' . NuDB::ident($pk) . ' = ?', [$new, $id]);
				$changes[] = ['rw' => $src['rw'], 'before' => $cur, 'after' => $new, 'label' => $src['gtitle'] . ' · ' . $src['label'], 'form' => (string)($src['form'] ?? ''), 'lines' => $n];
				$out['parts']++;
				$out['lines'] += $n;
			}
		});
		$u = NuAuth::currentRaw();
		$bid = nu_id();
		$db->insert(self::T, ['zzzzsys_nunext_code_replace_id' => $bid, 'created_at' => date('Y-m-d H:i:s'), 'login' => mb_substr((string)$u['login'], 0, 50),
			'search' => mb_substr($s['q'], 0, 1000), 'replace_with' => mb_substr($rep, 0, 1000), 'part_count' => $out['parts'], 'line_count' => $out['lines'],
			'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
		NuLog::write('code_replaced', 'codesearch', $bid, ['search' => mb_substr($s['q'], 0, 200), 'replace' => mb_substr($rep, 0, 200), 'parts' => $out['parts'], 'lines' => $out['lines'],
			'where' => array_slice(array_column($changes, 'label'), 0, 30)]);
		foreach ($db->page('SELECT zzzzsys_nunext_code_replace_id AS id FROM ' . self::T, [], 'created_at DESC', 1000, self::KEEP_BATCHES) as $r) $db->delete(self::T, 'zzzzsys_nunext_code_replace_id', (string)$r['id']);
		$out['ok'] = true;
		$out['batch'] = $bid;
		return $out;
	}

	/** Puts a run back: a part is restored only while it still holds what the run wrote. @return array{ok: bool, error: string, done: int, skipped: int} */
	public static function undo(string $batchId): array
	{
		$out = ['ok' => false, 'error' => '', 'done' => 0, 'skipped' => 0];
		if (!self::canReplace()) { $out['error'] = t('err_forbidden'); return $out; }
		$db = nu_db();
		$b = $db->tableExists(self::T) ? $db->one('SELECT * FROM ' . self::T . ' WHERE zzzzsys_nunext_code_replace_id = ?', [$batchId]) : null;
		if (!$b || !empty($b['undone_at'])) { $out['error'] = t('err_invalid'); return $out; }
		$changes = array_reverse((array)json_decode((string)$b['changes'], true));
		$forms = array_filter(array_unique(array_map(static fn($c) => (string)($c['form'] ?? ''), $changes)));
		foreach ($forms as $fid) NuFormHistory::snapshot($fid, 'code search: undo');
		$db->transaction(static function (NuDB $db) use ($changes, &$out): void {
			foreach ($changes as $c) {
				[$tb, $pk, $id, $col] = $c['rw'];
				$cur = $db->value('SELECT ' . NuDB::ident($col) . ' FROM ' . NuDB::ident($tb) . ' WHERE ' . NuDB::ident($pk) . ' = ?', [$id]);
				if ((string)$cur !== (string)$c['after']) { $out['skipped']++; continue; }
				$db->run('UPDATE ' . NuDB::ident($tb) . ' SET ' . NuDB::ident($col) . ' = ? WHERE ' . NuDB::ident($pk) . ' = ?', [(string)$c['before'], $id]);
				$out['done']++;
			}
		});
		$u = NuAuth::currentRaw();
		$db->update(self::T, ['undone_at' => date('Y-m-d H:i:s'), 'undone_by' => mb_substr((string)$u['login'], 0, 50)], 'zzzzsys_nunext_code_replace_id', $batchId);
		NuLog::write('code_replace_undone', 'codesearch', $batchId, ['done' => $out['done'], 'skipped' => $out['skipped']]);
		$out['ok'] = true;
		return $out;
	}

	/** The latest runs (for Undo). @return list<array> */
	public static function batches(int $n = 10): array
	{
		$db = nu_db();
		if (!$db->tableExists(self::T)) return [];
		return $db->page('SELECT zzzzsys_nunext_code_replace_id AS id, created_at, login, search, replace_with, part_count, line_count, undone_at FROM ' . self::T, [], 'created_at DESC', $n, 0);
	}

	// ================================================================= export

	/** The hits as rows: group, part, place, language, line, code, link. @return list<list<string|int>> */
	public static function rows(array $res): array
	{
		$rows = [];
		foreach ($res['groups'] as $g) {
			foreach ($g['parts'] as $p) {
				foreach ($p['blocks'] as $b) {
					foreach ($b as $l) {
						if (!$l['hit']) continue;
						$rows[] = [$g['title'] . ($g['draft'] ? ' (' . t('cs_draft') . ')' : ''), (string)$p['label'], t(self::PLACES[$p['place']][0] ?? 'cs_pl_own'),
							strtoupper($p['lang']), (int)$l['n'], mb_substr(trim($l['text']), 0, 1000), nu_base_url() . self::lineUrl($p, (int)$l['n'], '')];
					}
				}
			}
		}
		return $rows;
	}

	/** The link of a hit line: the part's editor, at the line when it has a code editor (ck). */
	public static function lineUrl(array $part, int $line, string $q): string
	{
		$u = (string)$part['url'];
		if (($part['ck'] ?? '') === '') return $u;
		return $u . (str_contains($u, '?') ? '&' : '?') . http_build_query(['ck' => $part['ck'], 'cl' => $line] + ($q !== '' ? ['cq' => mb_substr($q, 0, 100)] : []));
	}
}
