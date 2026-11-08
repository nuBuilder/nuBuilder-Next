<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * User help pages (0.8.1023): help for the people who USE an application built with nuBuilder Next – written in the browser, no code.
 *
 * - Table zzzzsys_nunext_help: one row per page and language. The row with lang '' is the page itself (code, parent, order, access
 *   levels, active); rows with a language code are its translations (title, text, keywords only). The reader takes the row of the
 *   user's language, else the page itself.
 * - Text: HTML (TinyMCE, cleaned by NuHtmlClean::doc on save) or Markdown (NuMarkdown). Links to other pages: "#help=<code>".
 *   Pictures are files (0.8.1024): custom/userhelp/<sha256>.<ext>, served to signed-in users by ?p=userhelpimg&f=<name>; pictures inside
 *   the text (data:image) become files on save, an export carries them inside again, housekeeping removes files no page uses.
 * - Who reads: everyone signed in, or only the access levels of the page (any level of the user counts). Inside the text,
 *     [[level: sales, support]] … [[else]] … [[/level]]   shown only to these access levels (codes), [[else]] = for everybody else
 *     [[not: guest]] … [[/not]]                         hidden from these access levels
 *   Editors see every part, marked (or "View as" an access level). Hash variables (#USER_NAME# …) are filled in.
 * - Where it opens: a form (sfo_json nunext.help), a tab (nunext.help_tabs {tab id: code}) or a field (sob_all_json nunext.help)
 *   → the ? button / F1 / (?) next to the label open a side panel (assets/js/nuuserhelp.js); ?p=userhelp is the reader page.
 * - Admin right "userhelp" (view = list, change = edit): page ?p=helppages (pages/nuhelppages.php).
 */
final class NuUserHelp
{
	public const T = 'zzzzsys_nunext_help';
	public const CODE_RE = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,59}$/';
	public const FORMATS = ['html', 'md'];
	public const MAX_BODY = 8000000;
	/** Hash variables never filled into a help page. */
	private const NO_HASH = ['SESSION_ID', 'TABLE_ID'];

	private static ?array $rows = null;
	private static ?array $codes = null;
	private static ?array $ctx = null;

	public static function ready(): bool
	{
		try { return nu_db()->tableExists(self::T); } catch (Throwable) { return false; }
	}

	public static function forget(): void
	{
		self::$rows = null;
		self::$codes = null;
	}

	/** Every row (cached per request), without the text. @return list<array<string, mixed>> */
	public static function rows(): array
	{
		if (self::$rows !== null) return self::$rows;
		if (!self::ready()) return self::$rows = [];
		return self::$rows = nu_db()->all('SELECT zzzzsys_nunext_help_id AS id, code, lang, title, format, parent, sort_order, access, keywords, active, updated_at, updated_by FROM '
			. self::T . ' ORDER BY sort_order, title');
	}

	/** The pages themselves (lang ''), by code. */
	public static function bases(): array
	{
		$out = [];
		foreach (self::rows() as $r) if ((string)$r['lang'] === '') $out[(string)$r['code']] = $r;
		return $out;
	}

	public static function get(string $id): ?array
	{
		if (!self::ready() || $id === '') return null;
		return nu_db()->one('SELECT zzzzsys_nunext_help_id AS id, ' . self::T . '.* FROM ' . self::T . ' WHERE zzzzsys_nunext_help_id = ?', [$id]);
	}

	/** One row with its text: the page (lang '') or a translation. */
	public static function row(string $code, string $lang): ?array
	{
		if (!self::ready()) return null;
		return nu_db()->one('SELECT zzzzsys_nunext_help_id AS id, ' . self::T . '.* FROM ' . self::T . ' WHERE code = ? AND lang = ?', [$code, $lang]);
	}

	public static function count(): int
	{
		return count(self::bases());
	}

	/** Access level ids of a page ([] = everyone signed in). */
	public static function accessOf(array $r): array
	{
		$j = json_decode((string)($r['access'] ?? ''), true);
		return is_array($j) ? array_values(array_filter(array_map('strval', $j), static fn($x) => $x !== '')) : [];
	}

	// ------------------------------------------------------------------ who reads

	public static function canEdit(): bool
	{
		$u = NuAuth::user();
		return $u && (!empty($u['globeadmin']) || NuAdmin::can('userhelp', 'change'));
	}

	public static function canManage(): bool
	{
		$u = NuAuth::user();
		return $u && (!empty($u['globeadmin']) || NuAdmin::can('userhelp', 'view'));
	}

	/**
	 * Reading context: levels = access ids, codes = their codes (lower case), editor (sees inactive pages and every level), marks (parts
	 * shown with a label instead of filtered), as = the access level an editor views the help as.
	 */
	public static function ctx(?string $as = null, ?bool $marks = null): array
	{
		if ($as === null && $marks === null && self::$ctx !== null) return self::$ctx;
		$u = NuAuth::user();
		$editor = self::canManage();
		$ga = $u && !empty($u['globeadmin']);
		$levels = NuUserAccess::levels($u);
		if ($editor && $as !== null && $as !== '') $levels = $as === '*' ? [] : [$as];   // "*" = a user without any of the levels
		$codes = [];
		if ($levels) {
			$in = implode(',', array_fill(0, count($levels), '?'));
			foreach (nu_db()->all("SELECT sal_code FROM zzzzsys_access WHERE zzzzsys_access_id IN ($in)", $levels) as $a) $codes[] = mb_strtolower(trim((string)$a['sal_code']));
		}
		if ($ga && ($as === null || $as === '')) $codes[] = 'globeadmin';
		$viewAs = $editor && $as !== null && $as !== '';
		$c = ['levels' => $levels, 'codes' => $codes, 'editor' => $editor && !$viewAs, 'marks' => $editor && !$viewAs && ($marks ?? true), 'as' => $viewAs ? $as : '',
			'all' => $ga && !$viewAs];
		if ($as === null && $marks === null) self::$ctx = $c;
		return $c;
	}

	/** May this context read the page (row of lang '')? */
	public static function readable(?array $base, ?array $ctx = null): bool
	{
		if (!$base) return false;
		$ctx ??= self::ctx();
		if ($ctx['editor']) return true;
		if ((string)$base['active'] !== '1') return false;
		$acc = self::accessOf($base);
		if (!$acc || $ctx['all']) return true;
		return (bool)array_intersect($acc, $ctx['levels']);
	}

	/** Codes the current user can read. @return array<string, true> */
	public static function readableCodes(): array
	{
		if (self::$codes !== null) return self::$codes;
		$out = [];
		foreach (self::bases() as $code => $b) if (self::readable($b)) $out[$code] = true;
		return self::$codes = $out;
	}

	public static function canRead(string $code): bool
	{
		return $code !== '' && isset(self::readableCodes()[$code]);
	}

	/** The user's language (the language list of the installation), '' when only the page itself exists. */
	public static function userLang(): string
	{
		return nu_lang();
	}

	/** Title of a page in the user's language. */
	public static function titleOf(string $code, ?string $lang = null): string
	{
		$lang ??= self::userLang();
		$base = '';
		foreach (self::rows() as $r) {
			if ((string)$r['code'] !== $code) continue;
			if ((string)$r['lang'] === $lang && trim((string)$r['title']) !== '') return (string)$r['title'];
			if ((string)$r['lang'] === '') $base = (string)$r['title'];
		}
		return $base !== '' ? $base : $code;
	}

	/** Languages a page has translations in. @return list<string> */
	public static function langsOf(string $code): array
	{
		$out = [];
		foreach (self::rows() as $r) if ((string)$r['code'] === $code && (string)$r['lang'] !== '') $out[] = (string)$r['lang'];
		sort($out);
		return $out;
	}

	// ------------------------------------------------------------------ reading

	/**
	 * A page ready to show, or null (missing / not readable): code, title, html, lang (the row used), parent chain (crumbs) and children.
	 * @return array{code: string, title: string, html: string, lang: string, crumbs: list<array{code: string, title: string}>, children: list<array{code: string, title: string}>, id: string, active: bool}|null
	 */
	public static function page(string $code, ?array $ctx = null): ?array
	{
		$ctx ??= self::ctx();
		$bases = self::bases();
		$base = $bases[$code] ?? null;
		if (!self::readable($base, $ctx)) return null;
		$lang = self::userLang();
		$row = ($lang !== '' ? self::row($code, $lang) : null) ?? self::row($code, '');
		if (!$row) return null;
		if (trim((string)$row['body']) === '' && (string)$row['lang'] !== '') $row = self::row($code, '') ?? $row;   // empty translation → the page itself
		$crumbs = [];
		$seen = [$code => true];
		for ($p = (string)$base['parent']; $p !== '' && isset($bases[$p]) && !isset($seen[$p]); $p = (string)$bases[$p]['parent']) {
			$seen[$p] = true;
			if (self::readable($bases[$p], $ctx)) array_unshift($crumbs, ['code' => $p, 'title' => self::titleOf($p)]);
		}
		$children = [];
		foreach ($bases as $c => $b) if ((string)$b['parent'] === $code && $c !== $code && self::readable($b, $ctx)) $children[] = ['code' => (string)$c, 'title' => self::titleOf((string)$c)];
		$title = trim((string)$row['title']) !== '' ? (string)$row['title'] : (string)$base['title'];
		return ['code' => $code, 'title' => $title, 'html' => self::render((string)$row['body'], (string)$row['format'], $ctx), 'lang' => (string)$row['lang'],
			'crumbs' => $crumbs, 'children' => $children, 'id' => (string)$base['id'], 'active' => (string)$base['active'] === '1'];
	}

	/** The contents: readable pages as a tree (children under their parent; an unreadable or missing parent = top level). */
	public static function tree(?array $ctx = null): array
	{
		$ctx ??= self::ctx();
		$bases = self::bases();
		$items = [];
		foreach ($bases as $c => $b) {
			if (!self::readable($b, $ctx)) continue;
			$items[(string)$c] = ['code' => (string)$c, 'title' => self::titleOf((string)$c), 'parent' => (string)$b['parent'], 'sort' => (int)$b['sort_order'],
				'active' => (string)$b['active'] === '1', 'children' => []];
		}
		$top = [];
		$byParent = [];
		foreach ($items as $c => $it) {
			$p = $it['parent'];
			if ($p !== '' && $p !== $c && isset($items[$p])) $byParent[$p][] = $c; else $top[] = $c;
		}
		$sort = static function (array $codes) use ($items): array {
			usort($codes, static fn($a, $b) => [$items[$a]['sort'], mb_strtolower($items[$a]['title'])] <=> [$items[$b]['sort'], mb_strtolower($items[$b]['title'])]);
			return $codes;
		};
		$build = static function (array $codes, int $depth, array $path) use (&$build, $items, $byParent, $sort): array {
			$out = [];
			foreach ($sort($codes) as $c) {
				if (isset($path[$c]) || $depth > 8) continue;
				$it = $items[$c];
				$it['children'] = $build($byParent[$c] ?? [], $depth + 1, $path + [$c => true]);
				$out[] = $it;
			}
			return $out;
		};
		return $build($top, 0, []);
	}

	/** Text of a row → HTML for this reader: Markdown converted, conditions applied, hash variables filled, help links pointed at the reader. */
	public static function render(string $body, string $format, ?array $ctx = null): string
	{
		$ctx ??= self::ctx();
		if ($format === 'md') {
			$body = (string)preg_replace('~\]\(#help=([A-Za-z0-9][A-Za-z0-9_.-]{0,59})\)~', '](https://nunext-help.invalid/$1)', $body);
			$body = NuMarkdown::toHtml($body);
			$body = (string)preg_replace('~href="https://nunext-help\.invalid/([A-Za-z0-9_.-]+)"[^>]*>~', 'href="#help=$1">', $body);
		}
		$html = self::conditions($body, $ctx);
		$html = self::fillHash($html);
		return self::links($html, $ctx);
	}

	/**
	 * [[level: a, b]] … [[else]] … [[/level]] and [[not: a, b]] … [[else]] … [[/not]] (codes of access levels, any case; "globeadmin" =
	 * the globeadmin). A marker alone in a paragraph takes the paragraph with it. With $ctx['marks'] every part stays, inside a labelled frame.
	 */
	public static function conditions(string $html, array $ctx): string
	{
		if (!str_contains($html, '[[')) return $html;
		$tok = '\[\[\s*(?:(?:level|not)\s*:[^\]<>]{0,300}|else|/\s*(?:level|not))\s*\]\]';
		$html = (string)preg_replace('~<p[^>]*>\s*(?:&nbsp;|\s)*(' . $tok . ')(?:&nbsp;|\s)*</p>~i', '$1', $html);
		$parts = preg_split('~(' . $tok . ')~i', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		if ($parts === false) return $html;
		$codes = array_map('mb_strtolower', $ctx['codes'] ?? []);
		$marks = !empty($ctx['marks']);
		$stack = [];                                              // frames: [kind, show-then, branch 'then'|'else', label]
		$visible = static function () use (&$stack): bool {
			foreach ($stack as $f) if (($f[2] === 'then') !== $f[1]) return false;
			return true;
		};
		$out = '';
		foreach ($parts as $p) {
			if (preg_match('~^\[\[\s*(level|not)\s*:([^\]]*)\]\]$~i', $p, $m)) {
				$list = array_values(array_filter(array_map(static fn($x) => mb_strtolower(trim(html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), explode(',', $m[2])), static fn($x) => $x !== ''));
				$hit = (bool)array_intersect($list, $codes);
				$kind = strtolower($m[1]);
				$show = $kind === 'level' ? $hit : !$hit;
				$label = t($kind === 'level' ? 'uh_cond_only' : 'uh_cond_not', implode(', ', $list));
				$stack[] = [$kind, $show, 'then', $label];
				if ($marks) $out .= '<div class="nuUhCond nuUhCond-' . $kind . '" data-cond="' . h($label) . '">';
				continue;
			}
			if (preg_match('~^\[\[\s*else\s*\]\]$~i', $p)) {
				if ($stack) {
					$stack[count($stack) - 1][2] = 'else';
					if ($marks) $out .= '</div><div class="nuUhCond nuUhCond-else" data-cond="' . h(t('uh_cond_else')) . '">';
				}
				continue;
			}
			if (preg_match('~^\[\[\s*/~', $p)) {
				if ($stack) { array_pop($stack); if ($marks) $out .= '</div>'; }
				continue;
			}
			if ($marks || $visible()) $out .= $p;
		}
		if ($marks) $out .= str_repeat('</div>', count($stack));
		return $out;
	}

	/** #USER_NAME#, #USER_FIRST_NAME#, #ACCESS_LEVEL_CODE#, dates … (text, escaped); unknown names stay as they are. */
	public static function fillHash(string $html): string
	{
		if (!preg_match('~#[A-Z][A-Z0-9_]{1,40}#~', $html)) return $html;
		try { $hash = NuHash::plain(); } catch (Throwable) { return $html; }
		return (string)preg_replace_callback('~#([A-Z][A-Z0-9_]{1,40})#~', static function ($m) use ($hash) {
			$k = $m[1];
			if (in_array($k, self::NO_HASH, true) || preg_match('~TOKEN|PASSWORD|SECRET~', $k) || !array_key_exists($k, $hash) || !is_scalar($hash[$k])) return $m[0];
			return h((string)$hash[$k]);
		}, $html);
	}

	/** href="#help=<code>" → the reader page (+ data-nu-uhelp for the side panel); a page this reader cannot open becomes plain text. */
	public static function links(string $html, array $ctx): string
	{
		if (!str_contains($html, '#help=')) return $html;
		$bases = self::bases();
		return (string)preg_replace_callback('~<a\s([^>]*?)href="#help=([A-Za-z0-9][A-Za-z0-9_.-]{0,59})(#[A-Za-z][\w-]{0,60})?"([^>]*)>(.*?)</a>~is', static function ($m) use ($bases, $ctx) {
			$code = $m[2];
			if (!self::readable($bases[$code] ?? null, $ctx)) return $m[5];
			$url = nu_url('userhelp', ['c' => $code]) . ($m[3] ?? '');
			return '<a ' . $m[1] . 'href="' . h($url) . '" data-nu-uhelp="' . h($code) . '"' . $m[4] . '>' . $m[5] . '</a>';
		}, $html);
	}

	/** First paragraph as plain text (the tooltip of a field's help mark). */
	public static function excerpt(string $code, int $max = 220): string
	{
		$p = self::page($code, ['marks' => false, 'editor' => false] + self::ctx());
		if (!$p) return '';
		$t = '';
		if (preg_match('~<p[^>]*>(.*?)</p>~is', $p['html'], $m)) $t = $m[1];
		$t = trim((string)preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($t !== '' ? $t : $p['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		if (mb_strlen($t) > $max) $t = rtrim(mb_substr($t, 0, $max - 1)) . '…';
		return $t;
	}

	/** Search the readable pages: title, keywords and text of the user's language and the page itself. @return list<array{code: string, title: string, snippet: string}> */
	public static function search(string $q, ?array $ctx = null): array
	{
		$ctx ??= self::ctx();
		$q = trim($q);
		if ($q === '' || !self::ready()) return [];
		$words = array_values(array_filter(preg_split('~\s+~u', mb_strtolower($q)) ?: [], static fn($w) => $w !== ''));
		$lang = self::userLang();
		$out = [];
		$bases = self::bases();
		foreach (nu_db()->all('SELECT code, lang, title, body, keywords, format FROM ' . self::T . " WHERE lang = '' OR lang = ?", [$lang]) as $r) {
			$code = (string)$r['code'];
			if (!self::readable($bases[$code] ?? null, $ctx)) continue;
			$text = html_entity_decode(strip_tags(self::conditions((string)$r['body'], ['marks' => false] + $ctx)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$hay = mb_strtolower($r['title'] . ' ' . $r['keywords'] . ' ' . $code . ' ' . $text);
			$ok = true;
			foreach ($words as $w) if (!str_contains($hay, $w)) { $ok = false; break; }
			if (!$ok) continue;
			$score = str_contains(mb_strtolower((string)$r['title']), $words[0]) ? 0 : 1;
			$snip = '';
			$pos = mb_stripos($text, $words[0]);
			if ($pos !== false) $snip = trim((string)preg_replace('~\s+~u', ' ', mb_substr($text, max(0, $pos - 60), 180)));
			$prev = $out[$code] ?? null;
			if ($prev && (string)$r['lang'] === '') continue;          // the translation wins
			$out[$code] = ['code' => $code, 'title' => self::titleOf($code), 'snippet' => $snip, 'score' => $score];
		}
		$out = array_values($out);
		usort($out, static fn($a, $b) => [$a['score'], mb_strtolower($a['title'])] <=> [$b['score'], mb_strtolower($b['title'])]);
		return array_map(static fn($x) => array_diff_key($x, ['score' => 1]), array_slice($out, 0, 30));
	}

	// ------------------------------------------------------------------ forms, tabs, fields

	/** Help of a form for the current user: page of the whole form and of its tabs (only codes the user may read). */
	public static function forForm(NuFormMeta $meta): array
	{
		$n = NuFormMeta::nunextJson($meta->form['sfo_json'] ?? null);
		$form = self::cleanCode($n['help'] ?? null);
		$tabs = [];
		foreach ((array)($n['help_tabs'] ?? []) as $tab => $code) if (($c = self::cleanCode($code)) !== null && self::canRead($c)) $tabs[(string)$tab] = $c;
		return ['form' => $form !== null && self::canRead($form) ? $form : '', 'tabs' => $tabs];
	}

	/** Help code of an object (sob_all_json nunext.help) when the user may read it, else ''. */
	public static function forObject(array $o): string
	{
		$c = self::cleanCode((($o['nunext'] ?? null) ?: NuFormMeta::nunextJson($o['sob_all_json'] ?? null))['help'] ?? null);
		return $c !== null && self::canRead($c) ? $c : '';
	}

	public static function cleanCode(mixed $v): ?string
	{
		if (!is_scalar($v)) return null;
		$s = trim((string)$v);
		return $s !== '' && preg_match(self::CODE_RE, $s) ? $s : null;
	}

	/** help / help_tabs of a form.save field list (null = remove the key). */
	public static function formKeysIn(array $fields): array
	{
		$out = [];
		if (array_key_exists('help', $fields)) $out['help'] = self::cleanCode($fields['help']);
		if (array_key_exists('help_tabs', $fields)) {
			$t = [];
			foreach (is_array($fields['help_tabs']) ? $fields['help_tabs'] : [] as $tab => $code) {
				if (is_string($tab) && preg_match('~^[\w.-]{1,40}$~', $tab) && ($c = self::cleanCode($code)) !== null) $t[$tab] = $c;
			}
			$out['help_tabs'] = $t ?: null;
		}
		return $out;
	}

	/** Where each page is used: code => list of [form id, form code / description, place text]. */
	public static function usage(): array
	{
		$out = [];
		if (!self::ready()) return $out;
		$db = nu_db();
		$forms = nu_forms();
		$tabTitles = [];
		foreach ($db->all("SELECT zzzzsys_form_id, sfo_json FROM zzzzsys_form WHERE sfo_json LIKE '%\"help%'") as $f) {
			$n = NuFormMeta::nunextJson((string)$f['sfo_json']);
			$fid = (string)$f['zzzzsys_form_id'];
			$name = (string)($forms[$fid]['description'] ?? '') ?: (string)($forms[$fid]['code'] ?? $fid);
			if (($c = self::cleanCode($n['help'] ?? null)) !== null) $out[$c][] = [$fid, $name, t('uh_used_form')];
			foreach ((array)($n['help_tabs'] ?? []) as $tab => $code) {
				if (($c = self::cleanCode($code)) === null) continue;
				$tabTitles[$tab] ??= (string)$db->value('SELECT syt_title FROM zzzzsys_tab WHERE zzzzsys_tab_id = ?', [(string)$tab]);
				$out[$c][] = [$fid, $name, t('uh_used_tab', $tabTitles[$tab] ?: (string)$tab)];
			}
		}
		foreach ($db->all("SELECT sob_all_zzzzsys_form_id, sob_all_id, sob_all_label, sob_all_json FROM zzzzsys_object WHERE sob_all_json LIKE '%\"help\"%'") as $o) {
			$c = self::cleanCode(NuFormMeta::nunextJson((string)$o['sob_all_json'])['help'] ?? null);
			if ($c === null) continue;
			$fid = (string)$o['sob_all_zzzzsys_form_id'];
			$name = (string)($forms[$fid]['description'] ?? '') ?: (string)($forms[$fid]['code'] ?? $fid);
			$lbl = trim(strip_tags((string)$o['sob_all_label']));
			$out[$c][] = [$fid, $name, t('uh_used_field', $lbl !== '' ? $lbl : (string)$o['sob_all_id'])];
		}
		return $out;
	}

	/** [code, title] of every page – the suggestions of the design mode fields. */
	public static function codeList(): array
	{
		$out = [];
		foreach (self::bases() as $c => $b) $out[] = [(string)$c, (string)$b['title']];
		usort($out, static fn($a, $b) => strnatcasecmp($a[0], $b[0]));
		return $out;
	}

	// ------------------------------------------------------------------ editing

	/**
	 * Saves a page or a translation. $in: code, lang, title, body, format, parent, sort_order, access (ids), keywords, active.
	 * A translation keeps only title / body / format / keywords – the rest belongs to the page. @return array{0: string, 1: array<string, string>}
	 */
	public static function save(array $in, string $id = ''): array
	{
		NuSchema::ensure();
		$db = nu_db();
		$old = $id !== '' ? self::get($id) : null;
		$lang = $old ? (string)$old['lang'] : trim((string)($in['lang'] ?? ''));
		$code = $old && $lang !== '' ? (string)$old['code'] : trim((string)($in['code'] ?? ''));
		$err = [];
		if (!preg_match(self::CODE_RE, $code)) $err['code'] = t('uh_err_code');
		if ($lang !== '' && !in_array($lang, array_keys(NU_LANGS), true)) $err['lang'] = t('uh_err_lang');
		$title = trim((string)($in['title'] ?? ''));
		if ($title === '' && $lang === '') $err['title'] = t('err_required');
		$format = in_array($in['format'] ?? '', self::FORMATS, true) ? (string)$in['format'] : 'html';
		$body = (string)($in['body'] ?? '');
		if (strlen($body) > self::MAX_BODY) $err['body'] = t('uh_err_size');
		if (!$err) {
			$dupe = (int)$db->value('SELECT COUNT(*) FROM ' . self::T . ' WHERE code = ? AND lang = ? AND zzzzsys_nunext_help_id <> ?', [$code, $lang, $id]);
			if ($dupe) $err[$lang === '' ? 'code' : 'lang'] = $lang === '' ? t('uh_err_code_taken') : t('uh_err_lang_taken');
			if ($lang !== '' && !self::row($code, '')) $err['code'] = t('record_not_found');
		}
		$parent = '';
		if ($lang === '') {
			$parent = trim((string)($in['parent'] ?? ''));
			if ($parent !== '') {
				$bases = self::bases();
				if (!isset($bases[$parent])) $err['parent'] = t('record_not_found');
				else for ($p = $parent, $i = 0; $p !== '' && $i < 50; $p = (string)($bases[$p]['parent'] ?? ''), $i++) {
					if ($p === $code || ($old && $p === (string)$old['code'])) { $err['parent'] = t('uh_err_parent'); break; }
				}
			}
		}
		if ($err) return [$id, $err];
		$clean = $format === 'html' ? NuHtmlClean::doc(self::extractImages($body)) : str_replace(["\r\n", "\r"], "\n", $body);
		$row = ['title' => mb_substr($title, 0, 200), 'body' => $clean, 'format' => $format, 'keywords' => mb_substr(trim((string)($in['keywords'] ?? '')), 0, 500),
			'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => (string)(NuAuth::user()['login'] ?? '')];
		if ($lang === '') {
			$acc = array_values(array_unique(array_filter(array_map('strval', (array)($in['access'] ?? [])), static fn($x) => preg_match('~^[\w.-]{1,40}$~', $x))));
			$row += ['code' => $code, 'parent' => $parent, 'sort_order' => max(-9999, min(99999, (int)($in['sort_order'] ?? 0))), 'access' => $acc ? json_encode($acc) : '',
				'active' => !empty($in['active']) ? '1' : '0'];
		}
		$db->transaction(static function (NuDB $db) use (&$id, $old, $row, $code, $lang): void {
			if ($old) {
				$db->update(self::T, $row, 'zzzzsys_nunext_help_id', $id);
				if ($lang === '' && (string)$old['code'] !== $code) {         // renamed: translations and children follow
					$db->run('UPDATE ' . self::T . " SET code = ? WHERE code = ? AND lang <> ''", [$code, (string)$old['code']]);
					$db->run('UPDATE ' . self::T . ' SET parent = ? WHERE parent = ?', [$code, (string)$old['code']]);
				}
			} else {
				$id = nu_id();
				$db->insert(self::T, ['zzzzsys_nunext_help_id' => $id, 'lang' => $lang, 'created_at' => $row['updated_at']] + $row + ($lang !== '' ? ['code' => $code, 'parent' => '', 'sort_order' => 0, 'access' => '', 'active' => '1'] : []));
			}
		});
		self::forget();
		NuLog::write($old ? 'userhelp_changed' : 'userhelp_created', self::T, $id, ['code' => $code, 'lang' => $lang]);
		return [$id, []];
	}

	/** Deletes a translation, or a page with its translations (its children move up to its parent). */
	public static function delete(string $id): bool
	{
		$r = self::get($id);
		if (!$r) return false;
		$db = nu_db();
		$db->transaction(static function (NuDB $db) use ($r, $id): void {
			if ((string)$r['lang'] !== '') { $db->run('DELETE FROM ' . self::T . ' WHERE zzzzsys_nunext_help_id = ?', [$id]); return; }
			$db->run('UPDATE ' . self::T . ' SET parent = ? WHERE parent = ?', [(string)$r['parent'], (string)$r['code']]);
			$db->run('DELETE FROM ' . self::T . ' WHERE code = ?', [(string)$r['code']]);
		});
		self::forget();
		NuLog::write('userhelp_deleted', self::T, $id, ['code' => (string)$r['code'], 'lang' => (string)$r['lang']]);
		return true;
	}

	// ------------------------------------------------------------------ export / import

	/** Every page and translation as JSON (format nunext-help-1); access levels by their code, so another installation can map them. */
	public static function export(): string
	{
		$acc = [];
		foreach (nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access') as $a) $acc[(string)$a['zzzzsys_access_id']] = (string)$a['sal_code'];
		$pages = [];
		foreach (nu_db()->all('SELECT * FROM ' . self::T . ' ORDER BY code, lang') as $r) {
			$p = ['code' => (string)$r['code'], 'lang' => (string)$r['lang'], 'title' => (string)$r['title'], 'format' => (string)$r['format'], 'body' => self::inlineImages((string)$r['body']),
				'keywords' => (string)$r['keywords']];
			if ((string)$r['lang'] === '') {
				$p += ['parent' => (string)$r['parent'], 'sort_order' => (int)$r['sort_order'], 'active' => (string)$r['active'] === '1',
					'access' => array_values(array_filter(array_map(static fn($x) => $acc[$x] ?? '', self::accessOf($r))))];
			}
			$pages[] = $p;
		}
		return (string)json_encode(['format' => 'nunext-help-1', 'version' => NU_VERSION, 'exported' => date('c'), 'pages' => $pages], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Imports nunext-help-1. $replace: pages with the same code + language are overwritten, else skipped. Access level codes that do not
	 * exist here are dropped (named in the result). @return array{added: int, replaced: int, skipped: int, errors: list<string>, unknown: list<string>}
	 */
	public static function import(string $json, bool $replace): array
	{
		$res = ['added' => 0, 'replaced' => 0, 'skipped' => 0, 'errors' => [], 'unknown' => []];
		$j = json_decode($json, true);
		if (!is_array($j) || ($j['format'] ?? '') !== 'nunext-help-1' || !is_array($j['pages'] ?? null)) { $res['errors'][] = t('uh_err_import'); return $res; }
		NuSchema::ensure();
		$byCode = [];
		foreach (nu_db()->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access') as $a) $byCode[mb_strtolower((string)$a['sal_code'])] = (string)$a['zzzzsys_access_id'];
		$pages = array_values(array_filter($j['pages'], 'is_array'));
		usort($pages, static fn($a, $b) => ((string)($a['lang'] ?? '') === '' ? 0 : 1) <=> ((string)($b['lang'] ?? '') === '' ? 0 : 1));   // pages before translations
		$parents = [];
		foreach ($pages as $p) {
			$code = (string)($p['code'] ?? '');
			$lang = (string)($p['lang'] ?? '');
			$old = self::ready() ? self::row($code, $lang) : null;
			if ($old && !$replace) { $res['skipped']++; continue; }
			$acc = [];
			foreach ((array)($p['access'] ?? []) as $ac) { $k = mb_strtolower((string)$ac); if (isset($byCode[$k])) $acc[] = $byCode[$k]; else $res['unknown'][] = (string)$ac; }
			if ($lang === '') $parents[$code] = (string)($p['parent'] ?? '');
			[$id, $err] = self::save(['code' => $code, 'lang' => $lang, 'title' => $p['title'] ?? '', 'body' => $p['body'] ?? '', 'format' => $p['format'] ?? 'html',
				'keywords' => $p['keywords'] ?? '', 'sort_order' => $p['sort_order'] ?? 0, 'access' => $acc, 'active' => $p['active'] ?? true, 'parent' => ''], $old ? (string)$old['id'] : '');
			if ($err) { $res['errors'][] = $code . ($lang !== '' ? " ($lang)" : '') . ': ' . implode(' ', $err); continue; }
			$res[$old ? 'replaced' : 'added']++;
		}
		$bases = self::bases();
		foreach ($parents as $code => $parent) {                   // parents last: they may come later in the file
			if ($parent !== '' && isset($bases[$parent], $bases[$code]) && $parent !== $code) nu_db()->update(self::T, ['parent' => $parent], 'zzzzsys_nunext_help_id', (string)$bases[$code]['id']);
		}
		self::forget();
		$res['unknown'] = array_values(array_unique($res['unknown']));
		NuLog::write('userhelp_imported', self::T, '', ['added' => $res['added'], 'replaced' => $res['replaced'], 'skipped' => $res['skipped']]);
		return $res;
	}

	// ------------------------------------------------------------------ markup for the form pages

	/** Help pages exist that this user may read (cached) – only then the form page loads the panel script. */
	public static function any(): bool
	{
		return self::ready() && (bool)self::readableCodes();
	}

	/** The ? button of a form page (form / tab pages of forForm()), or ''. */
	public static function button(array $fh): string
	{
		if ($fh['form'] === '' && !$fh['tabs']) return '';
		return '<button type="button" class="btn btn-outline-secondary nuActionButton nuHelpBtn" id="nuHelpButton" data-nu-uhelp="' . h($fh['form']) . '" data-nu-uhelp-tabs="'
			. h((string)json_encode((object)$fh['tabs'])) . '" data-nu-uhelp-base="' . h(nu_url('userhelp')) . '" title="' . h(t('uh_btn_tip')) . '" aria-label="' . h(t('uh_btn_tip')) . '">'
			. '<i class="bi bi-question-circle" aria-hidden="true"></i><span class="nuHelpBtnTxt ms-1">' . h(t('uh_btn')) . '</span></button>';
	}

	/** (?) after the label of a field with a help page: the first paragraph as tooltip, a click opens the panel. */
	public static function mark(array $o): string
	{
		if (!self::any()) return '';
		$code = self::forObject($o);
		if ($code === '') return '';
		$ex = self::excerpt($code);
		$name = t('uh_mark', self::titleOf($code));
		return ' <span class="nuUhTip" role="button" tabindex="0" data-nu-uhelp="' . h($code) . '" title="' . h($ex !== '' ? $ex : $name) . '" aria-label="' . h($name) . '">'
			. '<i class="bi bi-question-circle" aria-hidden="true"></i></span>';
	}

	/** Texts of the side panel + the script (end of a form page). */
	public static function foot(): string
	{
		return '<div id="nuUhPanelTexts" hidden data-uhelp-base="' . h(nu_url('userhelp')) . '" data-close="' . h(t('close')) . '" data-back="' . h(t('back')) . '" data-newtab="' . h(t('uh_newtab'))
			. '" data-loading="' . h(t('uh_loading')) . '" data-error="' . h(t('uh_not_found')) . '" data-insection="' . h(t('uh_in_section')) . '" data-edit="' . h(t('edit')) . '"></div>'
			. '<script src="' . nu_asset('assets/js/nuuserhelp.js') . '"></script>';
	}

	// ------------------------------------------------------------------ pictures as files (0.8.1024)

	public const IMG_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
	public const IMG_MAX = 10 * 1024 * 1024;
	public const IMG_RE = '[a-f0-9]{64}\.(?:png|jpg|gif|webp)';

	/** Folder of the pictures (deny-all .htaccess; served only by ?p=userhelpimg). */
	public static function imgDir(): string
	{
		$dir = NU_ROOT . '/custom/userhelp';
		if (!is_dir($dir) || !is_file($dir . '/.htaccess')) NuSecurity::protectDir($dir);
		return $dir;
	}

	public static function imgUrl(string $name): string
	{
		return 'index.php?p=userhelpimg&f=' . $name;
	}

	/** Stores a picture (PNG, JPEG, GIF, WebP – checked by its content, not its name); same content = same file. Returns the address or null. */
	public static function storeImage(string $bytes): ?string
	{
		if ($bytes === '' || strlen($bytes) > self::IMG_MAX) return null;
		$info = @getimagesizefromstring($bytes);
		$ext = is_array($info) ? (self::IMG_TYPES[(string)($info['mime'] ?? '')] ?? null) : null;
		if ($ext === null) return null;
		$name = hash('sha256', $bytes) . '.' . $ext;
		$path = self::imgDir() . '/' . $name;
		if (!is_file($path)) {
			$tmp = $path . '.' . bin2hex(random_bytes(4)) . '.part';
			if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $path)) { @unlink($tmp); return null; }
		}
		return self::imgUrl($name);
	}

	/** data:image/…;base64 pictures inside a text → files (address in src). Pictures that are not valid images are left for the cleaner. */
	public static function extractImages(string $html): string
	{
		if (!str_contains($html, 'data:image/')) return $html;
		return (string)preg_replace_callback('~(src\s*=\s*["\'])data:image/[a-z+.-]+;base64,([a-z0-9+/=\s]+)(["\'])~i', static function ($m) {
			$bytes = base64_decode((string)preg_replace('~\s+~', '', $m[2]), true);
			$url = $bytes !== false ? self::storeImage($bytes) : null;
			return $url !== null ? $m[1] . h($url) . $m[3] : $m[0];
		}, $html);
	}

	/** The reverse for an export: the pictures of a text as data:image (an import makes files of them again). */
	public static function inlineImages(string $html): string
	{
		return (string)preg_replace_callback('~index\.php\?p=userhelpimg&(?:amp;)?f=(' . self::IMG_RE . ')~', static function ($m) {
			$path = self::imgDir() . '/' . $m[1];
			if (!is_file($path)) return $m[0];
			$mime = array_search(pathinfo($m[1], PATHINFO_EXTENSION), self::IMG_TYPES, true) ?: 'image/png';
			return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
		}, $html);
	}

	/** Sends a picture to a signed-in user (the route checks the session). */
	public static function serveImage(string $name): void
	{
		$path = preg_match('~^' . self::IMG_RE . '$~', $name) ? self::imgDir() . '/' . $name : '';
		if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
		session_write_close();
		$mime = array_search(pathinfo($name, PATHINFO_EXTENSION), self::IMG_TYPES, true) ?: 'application/octet-stream';
		header('Content-Type: ' . $mime);
		header('Content-Length: ' . (string)filesize($path));
		header('Cache-Control: private, max-age=31536000, immutable');   // the name is the hash of the content
		header('X-Content-Type-Options: nosniff');
		header("Content-Security-Policy: default-src 'none'");
		readfile($path);
		exit;
	}

	/** Housekeeping: pictures no page (or translation) uses any more, older than a day (a page being written may not be saved yet). */
	public static function purgeImages(): int
	{
		$dir = NU_ROOT . '/custom/userhelp';
		if (!is_dir($dir) || !self::ready()) return 0;
		$used = [];
		foreach (nu_db()->all('SELECT body FROM ' . self::T . " WHERE body LIKE '%userhelpimg%'") as $r) {
			if (preg_match_all('~userhelpimg&(?:amp;)?f=(' . self::IMG_RE . ')~', (string)$r['body'], $m)) foreach ($m[1] as $n) $used[$n] = true;
		}
		$n = 0;
		foreach (glob($dir . '/*') ?: [] as $f) {
			$b = basename($f);
			if (preg_match('~^' . self::IMG_RE . '$~', $b) && !isset($used[$b]) && filemtime($f) < time() - 86400 && @unlink($f)) $n++;
			elseif (str_ends_with($b, '.part') && filemtime($f) < time() - 3600) @unlink($f);
		}
		return $n;
	}

	// ------------------------------------------------------------------ AI draft

	/**
	 * Forms a form leads to (0.8.1025): its sub-forms (a table of rows inside the screen), the forms behind its lookups (the list to pick
	 * a value from) and the forms its buttons open. @return list<array{id: string, name: string, kind: string, via: list<string>, on: bool}>
	 * (on = proposed: sub-forms, they are part of the screen)
	 */
	public static function related(string $formId): array
	{
		$meta = NuFormMeta::load($formId);
		if (!$meta) return [];
		$forms = nu_forms();
		$out = [];
		foreach ($meta->tabs as $t) foreach ($meta->objectsOfTab((string)$t['zzzzsys_tab_id']) as $o) {
			$type = (string)$o['sob_all_type'];
			$target = '';
			$kind = '';
			if ($type === 'subform') { $target = (string)$o['sob_subform_zzzzsys_form_id']; $kind = 'subform'; }
			elseif ($type === 'lookup') { $target = (string)$o['sob_lookup_zzzzsys_form_id']; $kind = 'lookup'; }
			elseif ($type === 'run' && !(NuFormMeta::nunextJson($o['sob_all_json'] ?? null)['url'] ?? '')) {
				$rt = strtoupper(trim((string)($o['sob_run_type'] ?? '')));
				$target = (string)$o['sob_run_zzzzsys_form_id'];
				if (($rt === 'F' || $rt === '') && isset($forms[$target])) $kind = 'button';
			}
			if ($kind === '' || $target === '' || $target === $formId || !isset($forms[$target])) continue;
			$label = trim(html_entity_decode(strip_tags((string)$o['sob_all_label']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			$key = $target . '|' . $kind;
			$out[$key] ??= ['id' => $target, 'name' => (string)($forms[$target]['description'] ?? '') ?: (string)($forms[$target]['code'] ?? $target), 'kind' => $kind, 'via' => [], 'on' => $kind === 'subform'];
			if ($label !== '' && !in_array($label, $out[$key]['via'], true)) $out[$key]['via'][] = $label;
		}
		$order = ['subform' => 0, 'lookup' => 1, 'button' => 2];
		$out = array_values($out);
		usort($out, static fn($a, $b) => [$order[$a['kind']], mb_strtolower($a['name'])] <=> [$order[$b['kind']], mb_strtolower($b['name'])]);
		return $out;
	}

	/** The fields of a form for a prompt: per tab (several tabs) or flat; $list = the columns of its list instead (lookups). @return list<string> */
	private static function fieldLines(NuFormMeta $meta, bool $list = false): array
	{
		$lines = [];
		if ($list) {
			foreach ($meta->browse as $b) {
				$tt = trim(html_entity_decode(strip_tags((string)$b['sbr_title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				if ($tt !== '' && (int)($b['sbr_width'] ?? 1) !== 0) $lines[] = '- ' . $tt;
			}
			return $lines;
		}
		$tabs = [];
		foreach ($meta->tabs as $t) $tabs[(string)$t['zzzzsys_tab_id']] = trim(strip_tags((string)$t['syt_title']));
		$many = count($tabs) > 1;
		foreach ($tabs as $tid => $tt) {
			if ($many) { $lines[] = ''; $lines[] = '### Tab: ' . ($tt !== '' ? $tt : '(untitled)'); }
			foreach ($meta->objectsOfTab($tid) as $o) {
				$type = (string)$o['sob_all_type'];
				if (in_array($type, ['html', 'contentbox'], true)) continue;
				$label = trim(html_entity_decode(strip_tags((string)$o['sob_all_label']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				$extra = [];
				if ($type === 'input') $extra[] = (string)$o['sob_input_type'];
				if (in_array((string)$o['sob_all_validate'], ['1', '3'], true)) $extra[] = 'required';
				if (in_array((string)$o['sob_all_validate'], ['2', '3'], true)) $extra[] = 'no duplicates';
				if ($type === 'select' && NuSelectSource::isFixed((string)$o['sob_select_sql'])) {
					$opts = array_slice(array_map(static fn($x) => (string)($x[1] ?? $x[0] ?? ''), NuSelectSource::options((string)$o['sob_select_sql'], [])), 0, 12);
					if ($opts) $extra[] = 'choices: ' . implode(' / ', $opts);
				}
				if ($type === 'subform') $extra[] = 'a table of rows';
				if ($type === 'lookup') $extra[] = 'value picked from a list';
				if ($type === 'run' || ($type === 'input' && (string)$o['sob_input_type'] === 'button')) $extra[] = 'button';
				$lines[] = '- ' . ($label !== '' ? $label : (string)$o['sob_all_id']) . ' (' . $type . ($extra ? ', ' . implode(', ', $extra) : '') . ')';
			}
		}
		return $lines;
	}

	/**
	 * A prompt for an AI chat: write a help page for the end users of a form (its tabs, fields and their labels) in the given language;
	 * $related = ids of related() forms to describe as well (others are ignored).
	 */
	public static function aiPrompt(string $formId, string $lang, string $note = '', array $related = []): string
	{
		$meta = NuFormMeta::load($formId);
		if (!$meta) return '';
		$name = static fn(NuFormMeta $m) => ($d = trim(strip_tags((string)$m->description()))) !== '' ? $d : $m->code();
		$extra = '';
		$want = array_flip(array_map('strval', $related));
		foreach (self::related($formId) as $r) {
			if (!isset($want[$r['id']])) continue;
			$rm = NuFormMeta::load($r['id']);
			if (!$rm) continue;
			$via = $r['via'] ? ' "' . implode('", "', $r['via']) . '"' : '';
			$what = ['subform' => "a table of rows inside this screen (field$via) – its columns:",
				'lookup' => "the list a user picks a value from (field$via) – its columns:",
				'button' => "a screen opened by the button$via – its fields:"][$r['kind']];
			$lines = self::fieldLines($rm, $r['kind'] === 'lookup');
			$extra .= "\n## Related: \"" . $name($rm) . "\" – $what\n" . implode("\n", $lines ?: ['- (no fields)']) . "\n";
		}
		$language = NU_LANGS[$lang][1] ?? $lang;
		return "Write a help page for the end users of a screen in a business application.\n\n"
			. "Screen: \"" . $name($meta) . "\"\n"
			. "Language of the help page: $language\n"
			. ($note !== '' ? "What the help page should focus on: $note\n" : '')
			. "\nRules:\n"
			. "- Explain what the screen is for, then how to do the usual tasks step by step, then what each field means (only fields a user needs to understand).\n"
			. ($extra !== '' ? "- Related screens are listed after the screen: explain how they are used from this screen (adding rows, picking a value, what a button opens), not as screens of their own.\n" : '')
			. "- Short sentences, plain words, no technical terms (no table, column, SQL or code names).\n"
			. "- Answer with HTML only, no <html>, <head> or <body>: use <h2>, <h3>, <p>, <ul>/<ol>/<li>, <strong>, <table> where it helps. No styles, no scripts.\n"
			. "- Parts only some users should see can be wrapped in a paragraph [[level: code]] before and [[/level]] after (codes of access levels); leave them out unless I ask.\n"
			. "\n## The screen \"" . $name($meta) . "\" – its fields (label, type, notes):\n" . implode("\n", self::fieldLines($meta)) . "\n" . $extra;
	}
}
