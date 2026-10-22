<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Function reference of the Help page (0.8.399): docs/nunext-functions.json → two sections of docs/nunext-docs.htm
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

	/** HTML of one reference (search field, A–Z index, one .doc-sub per group). */
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

		// A–Z index
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
			$h .= '<div class="doc-sub nuFnGroup" id="' . h($gid) . '"><h3 id="' . h($gid) . '-h">' . h((string)$g['title']) . '</h3>';
			foreach ($list as $it) $h .= self::entry($lang, $it);
			$h .= '</div>';
		}
		return $h;
	}

	private static function sortKey(string $n): string
	{
		foreach (self::PREFIXES as $p) if (str_starts_with($n, $p) && strlen($n) > strlen($p)) return substr($n, strlen($p));
		return $n;
	}

	private static function entry(string $lang, array $it): string
	{
		$n = (string)$it['n'];
		$next = ($it['o'] ?? 'nb') === 'next';
		$v = (string)($it['v'] ?? '');
		$badge = $next
			? '<span class="badge nuFnNext">nuBuilder Next' . ($v !== '' ? ' ' . h($v) : '') . '</span>'
			: (($it['k'] ?? 'fn') === 'hook' ? '<span class="badge nuFnHook">hook</span>' : '<span class="badge nuFnNb">nuBuilder</span>');
		$h = '<div class="nuFn" id="' . h(self::anchor($lang, $n)) . '" data-nu-fn="' . h(strtolower($n)) . '">'
			. '<div class="nuFnHead"><code class="nuFnSig">' . h((string)$it['s']) . '</code>' . $badge . '</div>'
			. '<div class="nuFnDesc">' . h((string)$it['d']) . '</div>';
		if (!empty($it['r'])) $h .= '<div class="nuFnRet"><span>Returns</span> ' . h((string)$it['r']) . '</div>';
		if (!empty($it['e'])) $h .= '<pre class="doc-code">' . h((string)$it['e']) . '</pre>';
		return $h . '</div>';
	}
}
