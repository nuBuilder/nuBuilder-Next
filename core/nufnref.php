<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Function reference of the Help page: docs/nunext-functions.json → the page #ref of the tab Code reference (docs/pages/, see docs/toc.json)
 * (<div data-nu-fnref="php"></div> / "js" are replaced by render()). Search and index: assets/js/nuhelp.js.
 * dev/tests/run.php compares the documented functions with the code (codeNames()): a new PHP function in
 * core/form/nuphp_api.php / nuphp_compat.php, core/nudebug.php, core/numail.php or a new api('…') in
 * assets/js/nu/nuruntime.js / nucompat.js needs an entry.
 */
final class NuFnRef
{
	public const FILE = '/docs/nunext-functions.json';
	public const PHP_FILES = ['core/form/nuphp_api.php', 'core/form/nuphp_compat.php', 'core/nudebug.php', 'core/numail.php'];
	public const JS_FILES = ['assets/js/nu/nuruntime.js', 'assets/js/nu/nucompat.js'];
	/** Prefixes left out for the A–Z index (nuRunQuery → R). */
	private const PREFIXES = ['$sheet->', '$x->', '$nu->', 'NuXlsx::', 'NuPage.', 'nuHome.', 'nuDebug::', 'db_', 'nu'];

	private static ?array $data = null;

	/** The reference: ['php' => ['groups' => [...], 'items' => [...]], 'js' => …]; [] when the file is missing or broken. */
	public static function load(): array
	{
		if (self::$data !== null) return self::$data;
		$f = NU_ROOT . self::FILE;
		$d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
		return self::$data = is_array($d) ? $d : [];
	}

	/** Names of the documented functions of the code (entries without kind). */
	public static function documented(string $lang): array
	{
		$out = [];
		foreach (self::load()[$lang]['items'] ?? [] as $it) if (($it['k'] ?? 'fn') === 'fn') $out[] = (string)$it['n'];
		return $out;
	}

	/** Functions offered by the code: global PHP functions nu… / db_… (not nu_ helpers), JavaScript api('…') names. */
	public static function codeNames(string $lang): array
	{
		$out = [];
		foreach ($lang === 'php' ? self::PHP_FILES : self::JS_FILES as $f) {
			$src = (string)@file_get_contents(NU_ROOT . '/' . $f);
			if ($lang === 'php') {
				preg_match_all('/(?<!static )(?<!public )(?<!private )\bfunction\s+((?:nu|db_)[A-Za-z0-9_]*)\s*\(/', $src, $m);
				foreach ($m[1] as $n) if (!str_starts_with($n, 'nu_')) $out[] = $n;
			} else {
				preg_match_all("/\\bapi\\('([A-Za-z0-9_]+)'/", $src, $m);
				array_push($out, ...$m[1]);
			}
		}
		return array_values(array_unique($out));
	}

	public static function anchor(string $lang, string $name): string
	{
		return 'fn-' . $lang . '-' . trim((string)preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
	}

	/** Letter of the A–Z index. */
	public static function letter(string $name): string
	{
		foreach (self::PREFIXES as $p) {
			if (str_starts_with($name, $p) && strlen($name) > strlen($p)) { $name = substr($name, strlen($p)); break; }
		}
		$c = strtoupper(substr(ltrim($name, '$_'), 0, 1));
		return preg_match('/[A-Z]/', $c) ? $c : '#';
	}

	/**
	 * The two references as tabs: <div data-nu-fnref="tabs"> of the page docs/pages/10-04-ref.htm. Each tab is a .doc-sub (the help index
	 * lists "PHP functions" / "JavaScript functions"), ids ref-php / ref-js stay the anchors of older links; assets/js/nuhelp.js switches
	 * the tab for #ref-…, #fn-… and the index, and shows both while the help search runs.
	 */
	public static function tabs(): string
	{
		$langs = ['php' => 'PHP', 'js' => 'JavaScript'];
		$nav = '<ul class="nav nav-tabs nuFnTabs" role="tablist" data-nu-search-skip>';
		$panes = '';
		$first = true;
		foreach ($langs as $lang => $label) {
			$n = count(self::load()[$lang]['items'] ?? []);
			if (!$n) continue;
			$nav .= '<li class="nav-item" role="presentation"><button type="button" class="nav-link' . ($first ? ' active' : '') . '" role="tab" id="ref-' . $lang . '-tab"'
				. ' data-nu-fn-tab="ref-' . $lang . '" aria-controls="ref-' . $lang . '" aria-selected="' . ($first ? 'true' : 'false') . '">'
				. '<i class="bi ' . ($lang === 'php' ? 'bi-filetype-php' : 'bi-filetype-js') . ' me-1"></i>' . h($label) . ' <span class="badge rounded-pill nuFnTabCount">' . $n . '</span></button></li>';
			$panes .= '<div class="doc-sub nuFnPane' . ($first ? ' active' : '') . '" id="ref-' . $lang . '" role="tabpanel" aria-labelledby="ref-' . $lang . '-tab">'
				. '<h3 id="ref-' . $lang . '-h" class="nuFnPaneTitle">' . h($label) . ' functions</h3>' . self::render($lang) . '</div>';
			$first = false;
		}
		return $nav . '</ul>' . $panes;
	}

	/** HTML of one reference (search field, A–Z index, one block per group). */
	public static function render(string $lang): string
	{
		$ref = self::load()[$lang] ?? null;
		if (!is_array($ref) || empty($ref['items'])) return '';
		$items = $ref['items'];
		$byGroup = [];
		foreach ($items as $it) $byGroup[(string)$it['g']][] = $it;

		$label = $lang === 'php' ? 'PHP' : 'JavaScript';
		$h = '<div class="nuFnTools" data-nu-search-skip>'
			. '<input type="search" class="form-control nuFnSearch" data-nu-fn-search="' . h($lang) . '" placeholder="' . h(t('fnref_search', $label)) . '"'
			. ' aria-label="' . h(t('fnref_search', $label)) . '" autocomplete="off">'
			. '<span class="nuFnCount small text-body-secondary" aria-live="polite" data-nu-fn-count="' . h(t('fnref_count')) . '"></span></div>'
			. '<p class="nuFnNone small text-body-secondary" hidden>' . h(t('help_no_match')) . '</p>';

		// groups as chips, then the A–Z index
		$h .= '<nav class="nuFnGroupNav" data-nu-search-skip aria-label="' . h($label) . '">';
		foreach ($ref['groups'] ?? [] as $g) {
			$cnt = count($byGroup[(string)$g['id']] ?? []);
			if ($cnt) $h .= '<a href="#ref-' . h($lang) . '-' . h((string)$g['id']) . '">' . h((string)$g['title']) . ' <span>' . $cnt . '</span></a>';
		}
		$h .= '</nav>';
		$az = [];
		foreach ($items as $it) $az[self::letter((string)$it['n'])][(string)$it['n']] = true;
		ksort($az);
		$h .= '<details class="nuFnIndex" data-nu-search-skip><summary>' . h(t('fnref_index', count($items))) . '</summary><div class="nuFnAz">';
		foreach ($az as $l => $names) {
			$names = array_keys($names);
			usort($names, static fn($a, $b) => strcasecmp(self::sortKey($a), self::sortKey($b)));
			$h .= '<div class="nuFnLetter"><b>' . h($l) . '</b> ';
			foreach ($names as $n) $h .= '<a href="#' . h(self::anchor($lang, $n)) . '">' . h($n) . '</a> ';
			$h .= '</div>';
		}
		$h .= '</div></details>';

		foreach ($ref['groups'] ?? [] as $g) {
			$list = $byGroup[(string)$g['id']] ?? [];
			if (!$list) continue;
			$gid = 'ref-' . $lang . '-' . $g['id'];
			$h .= '<div class="nuFnGroup" id="' . h($gid) . '"><h4 class="nuFnGroupTitle" id="' . h($gid) . '-h">' . h((string)$g['title']) . '</h4>';
			foreach ($list as $it) $h .= self::entry($lang, $it, $list);
			$h .= '</div>';
		}
		return $h;
	}

	private static function sortKey(string $n): string
	{
		foreach (self::PREFIXES as $p) if (str_starts_with($n, $p) && strlen($n) > strlen($p)) return substr($n, strlen($p));
		return $n;
	}

	/** Parameters of a signature: [[name, type, default, variadic], …] – "string $code, ?string $userId = null" or "id, method". */
	public static function params(string $sig): array
	{
		$open = strpos($sig, '(');
		if ($open === false) return [];
		$depth = 0; $buf = ''; $parts = [];
		for ($i = $open + 1, $n = strlen($sig); $i < $n; $i++) {
			$c = $sig[$i];
			if ($c === '(' || $c === '[') $depth++;
			if ($c === ')' || $c === ']') { if ($depth === 0) break; $depth--; }
			if ($c === ',' && $depth === 0) { $parts[] = $buf; $buf = ''; continue; }
			$buf .= $c;
		}
		if (trim($buf) !== '') $parts[] = $buf;
		$out = [];
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p === '') continue;
			[$decl, $def] = array_pad(array_map('trim', explode('=', $p, 2)), 2, null);
			$var = (bool)preg_match('/^(.*?)(\.\.\.)?(&?\$\w+|\w+)$/', $decl, $m);
			$type = $var ? trim($m[1]) : '';
			$name = $var ? ($m[2] ?? '') . ltrim($m[3], '&') : $decl;
			$out[] = [$name, $type, $def, str_contains($decl, '...')];
		}
		return $out;
	}

	private static function entry(string $lang, array $it, array $group = []): string
	{
		$n = (string)$it['n'];
		$kind = (string)($it['k'] ?? 'fn');
		$next = ($it['o'] ?? 'nb') === 'next';
		$v = (string)($it['v'] ?? '');
		$badge = $next
			? '<span class="badge nuFnNext">nuBuilder Next</span>'   // the version ("v") stays in the JSON for the changelog, not in the help
			: ($kind === 'hook' ? '<span class="badge nuFnHook">hook</span>' : '<span class="badge nuFnNb">nuBuilder Forte</span>');
		if ($kind === 'method' && !$next) $badge .= '<span class="badge nuFnKind">method</span>';
		$h = '<article class="nuFn" id="' . h(self::anchor($lang, $n)) . '" data-nu-fn="' . h(strtolower($n)) . '">'
			. '<header class="nuFnHead"><h5 class="nuFnName">' . h($n) . '</h5>' . $badge
			. '<a class="nuFnLink" href="#' . h(self::anchor($lang, $n)) . '" title="Link" aria-label="Link to ' . h($n) . '"><i class="bi bi-link-45deg"></i></a></header>'
			. '<pre class="nuFnSig"><code>' . self::sigHtml((string)$it['s'], $n) . '</code></pre>'
			. '<dl class="nuFnSpec">';
		$params = self::params((string)$it['s']);
		$pd = (array)($it['p'] ?? []);
		if ($params) {
			$h .= '<dt>Parameters</dt><dd><ul class="nuFnParams">';
			foreach ($params as [$pn, $pt, $def, $variadic]) {
				$desc = $pd[$pn] ?? $pd[ltrim($pn, '.')] ?? '';
				$h .= '<li><code class="nuFnPName">' . h($pn) . '</code>'
					. ($pt !== '' ? ' <span class="nuFnPType">' . h($pt) . '</span>' : '')
					. ($def !== null ? ' <span class="nuFnPOpt">optional, default <code>' . h($def) . '</code></span>' : ($variadic ? ' <span class="nuFnPOpt">any number</span>' : ''))
					. ($desc !== '' ? '<span class="nuFnPDesc">' . h((string)$desc) . '</span>' : '') . '</li>';
			}
			$h .= '</ul></dd>';
		} elseif (isset($pd['...'])) {
			$h .= '<dt>Parameters</dt><dd><ul class="nuFnParams"><li><code class="nuFnPName">…</code><span class="nuFnPDesc">' . h((string)$pd['...']) . '</span></li></ul></dd>';
		}
		if (!empty($it['r'])) $h .= '<dt>' . ($kind === 'hook' ? 'Return' : 'Returns') . '</dt><dd>' . h((string)$it['r']) . '</dd>';
		$h .= '<dt>Description</dt><dd>' . h((string)$it['d']) . '</dd></dl>';
		if (!empty($it['e'])) {
			$h .= '<div class="nuFnBlock"><div class="nuFnBlockHead"><span>' . ($kind === 'hook' ? 'Define it in the form\'s JavaScript' : 'Example') . '</span>'
				. '<button type="button" class="nuFnCopy" data-nu-copy-text="' . h((string)$it['e']) . '" title="Copy" aria-label="Copy the example"><i class="bi bi-clipboard"></i></button></div>'
				. '<pre class="nuFnCode nuFnCode-' . $lang . '"><code>' . h((string)$it['e']) . '</code></pre></div>';
		}
		if (isset($it['x']) && (string)$it['x'] !== '') {
			$out = $lang === 'js' && str_contains((string)($it['e'] ?? ''), 'console.log(') ? 'console.log()' : 'Result';
			$h .= '<div class="nuFnBlock nuFnOut"><div class="nuFnBlockHead"><span>' . h($out) . '</span></div><pre class="nuFnCode"><code>' . h((string)$it['x']) . '</code></pre></div>';
		}
		$see = self::related($n, $group);
		if ($see) {
			$h .= '<p class="nuFnSee"><span>Also see:</span> ' . implode(', ', array_map(static fn($x) => '<a href="#' . h(self::anchor($lang, $x)) . '">' . h($x) . '</a>', $see)) . '</p>';
		}
		return $h . '</article>';
	}

	/** Signature with the function name in bold: "nuGetValue(id, method)". */
	private static function sigHtml(string $sig, string $name): string
	{
		$p = strpos($sig, $name);
		if ($p === false) return h($sig);
		return h(substr($sig, 0, $p)) . '<b>' . h($name) . '</b>' . h(substr($sig, $p + strlen($name)));
	}

	/** Up to four functions of the same group with a similar name (nuIsDisabled → nuIsEnabled, nuIsVisible, nuIsHidden). */
	public static function related(string $name, array $group): array
	{
		$core = static fn(string $s) => strtolower((string)preg_replace('/^(\$\w+->|NuXlsx::|NuPage\.|nuHome\.|db_|nu)/', '', (string)preg_replace('/\(.*$/', '', $s)));
		$me = $core($name);
		if (strlen($me) < 3) return [];
		$scores = [];
		foreach ($group as $o) {
			$on = (string)$o['n'];
			if ($on === $name) continue;
			$c = $core($on);
			similar_text($me, $c, $pct);
			$prefix = strlen($me) >= 4 && strlen($c) >= 4 && (str_starts_with($c, substr($me, 0, 4)) || str_ends_with($c, substr($me, -5)));
			if ($pct >= 62 || $prefix) $scores[$on] = $pct + ($prefix ? 25 : 0);
		}
		arsort($scores);
		return array_slice(array_keys($scores), 0, 4);
	}
}
