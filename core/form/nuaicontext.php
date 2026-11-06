<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * AI context pack (0.8.961): a file the user attaches to the AI chat together with the prompt of Form with AI / design mode's Ask AI,
 * so the AI knows nuBuilder Next's functions, hash variables, conventions and the exact shape of a form spec – not only the format
 * description of the prompt.
 *
 * One Markdown file (nunext-context-<kind>-<version>.md, every AI chat reads it) or a zip with one file per section. Built on the
 * server from the same sources as the help (docs/nunext-functions.json, docs/pages, NuHash::CATALOG, the bundled example forms of
 * install/ai-examples.json), so it describes the installed version. Never contains data rows, settings, nuconfig values or passwords:
 * table STRUCTURE only, and form code only of the form being changed (the user asked for it) and of the bundled examples.
 *
 * compact (default): one line per function (signature + first sentence), examples only for the most used ones, a few guide pages;
 * full: every function with parameters, result and example, more guide pages.
 */
final class NuAiContext
{
	/** Functions whose example the compact pack keeps (the ones form code uses most). */
	private const TOP = ['nuRunQuery', 'db_fetch_object', 'db_fetch_row', 'db_num_rows', 'nuDisplayError', 'nuSetProperty', 'nuGetProperty', 'nuJavaScriptCallback',
		'nuAddJavaScript', 'nuID', 'nuDebug', 'nuSendEmail', 'nuLookupRecord', 'nuSubformObject', 'nuGetNuDataValue', 'nuSetNuDataValue', 'nuHash', 'nuDecode',
		'nuGetValue', 'nuSetValue', 'nuHide', 'nuShow', 'nuEnable', 'nuDisable', 'nuMessage', 'nuRunPHPHidden', 'nuRunPHP', 'nuForm', 'nuPopup',
		'nuOnLoad', 'nuBeforeSave', 'nuAfterSave', 'nuIsNewRecord', 'nuSelectTab', 'nuSetLabelText', 'nuSetPlaceholder', 'nuAddActionButton', 'nuRunReport',
		'nuHasBeenEdited', 'nuSaveAction', 'nuSubformAddFilter', 'nuSetTitle'];

	/** Function groups the compact pack leaves out – start page hooks, custom pages, Excel export: not code of a form. */
	private const SKIP_COMPACT = ['php' => ['home', 'page', 'xlsx'], 'js' => ['page', 'home']];

	/** Guide pages included as text (docs/pages without .htm); the full pack adds FULL_PAGES. */
	private const PAGES = ['04-01-form-lifecycle', '04-05-object-types', '05-02-validation'];
	private const FULL_PAGES = ['05-01-save-flow', '05-03-procedures', '10-01-php-api', '10-02-js-api', '10-03-label-menu', '12-02-porting'];

	/** kind: form (a new form) | app (several forms) | form-<code> (change a form). Characters a file name cannot carry are dropped. */
	public static function kind(bool $app, ?NuFormMeta $meta): string
	{
		if ($meta) return 'form-' . (preg_replace('/[^A-Za-z0-9_-]+/', '', $meta->code()) ?: 'form');
		return $app ? 'app' : 'form';
	}

	public static function fileName(string $kind, string $ext): string
	{
		return 'nunext-context-' . $kind . '-' . NU_VERSION . '.' . $ext;
	}

	/**
	 * The sections [key => [title, markdown]] in reading order.
	 * @param array $tables the table choice of the wizard ([] = all, [NuFormSpec::NO_TABLES] = none)
	 */
	public static function sections(bool $app, ?NuFormMeta $meta, array $tables, ?array $draftSnap, bool $full): array
	{
		$s = [];
		$s['readme'] = ['How to use this file', self::readme($app, $meta, $full)];
		$s['format'] = [$app ? 'Request format "' . NuFormSpec::APP_FORMAT . '"' : 'Request format "' . NuFormSpec::FORMAT . '"',
			$app ? "```\n" . NuFormSpec::appFormatHelp() . "\n```\n\nEach form of the app uses the form format:\n\n```\n" . NuFormSpec::formatHelp() . "\n```"
				: "```\n" . NuFormSpec::formatHelp() . "\n```"];
		$spec = $draftSnap ? NuFormSpec::fromSnapshot($draftSnap) : ($meta ? NuFormSpec::fromForm($meta->id) : null);
		$s['tables'] = ['Database tables (structure only)', "Name: columns with their type. No data of the tables is included.\n\n"
			. NuFormSpec::schemaText((string)($spec['form']['table'] ?? ''), $full ? 400 : 80, $tables)];
		$s['forms'] = ['Existing forms (for lookups and sub-forms, by code)', NuFormSpec::formsText()];
		if ($spec) $s['current'] = ['The form to change' . ($draftSnap ? ' (its open draft)' : ''), "Return the COMPLETE form in this shape, changed as requested.\n\n```json\n"
			. self::json($spec) . "\n```"];
		$s['examples'] = ['Example forms', self::examples()];
		$s['hash'] = ['Hash variables', self::hashText()];
		$s['guide'] = ['How forms work', self::guide($full)];
		$s['php'] = ['PHP functions' . ($full ? '' : ' (compact)'), self::functions('php', $full)];
		$s['js'] = ['JavaScript functions' . ($full ? '' : ' (compact)'), self::functions('js', $full)];
		return $s;
	}

	/** One Markdown file of all sections. */
	public static function markdown(array $sections, string $kind): string
	{
		$out = '# nuBuilder Next – AI context (' . $kind . ")\n\n" . self::header() . "\n\n";
		$n = 0;
		foreach ($sections as $key => [$title, $md]) {
			$out .= ($key === 'readme' ? '## ' : '## ' . (++$n) . '. ') . $title . "\n\n" . trim($md) . "\n\n";
		}
		return $out;
	}

	/** A zip with one Markdown file per section (00-readme.md, 01-format.md …); null without ZipArchive. */
	public static function zip(array $sections, string $kind): ?string
	{
		if (!class_exists('ZipArchive')) return null;
		$tmp = tempnam(sys_get_temp_dir(), 'nuctx');
		if ($tmp === false) return null;
		$z = new ZipArchive();
		if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }
		$i = 0;
		foreach ($sections as $key => [$title, $md]) {
			$z->addFromString(sprintf('%02d-%s.md', $i++, $key), '# ' . $title . "\n\n" . ($key === 'readme' ? self::header() . "\n\n" : '') . trim($md) . "\n");
		}
		$z->close();
		$bin = (string)file_get_contents($tmp);
		@unlink($tmp);
		return $bin;
	}

	private static function header(): string
	{
		$db = nu_db()->type === 'sqlsrv' ? 'SQL Server' : 'MySQL / MariaDB';
		return '> nuBuilder Next ' . NU_VERSION . ' · database ' . $db . ' · made ' . date('Y-m-d H:i')
			. ". This file describes the installed version. It contains the structure of the database tables, but no data, settings or passwords.";
	}

	private static function readme(bool $app, ?NuFormMeta $meta, bool $full): string
	{
		return "This file goes with a prompt of nuBuilder Next's " . ($app ? '"Form with AI" (a small app: several forms)' : ($meta ? 'Ask AI (change a form)' : '"Form with AI" (a new form)'))
			. ". Read it before answering the prompt.\n\n"
			. "- nuBuilder Next is a front end for nuBuilder Forte 4.x on the same database tables (zzzzsys_*): forms, objects, browse lists, PHP hooks and JavaScript work as in nuBuilder Forte 4.9.\n"
			. "- Answer in the request format of section 1 – ONE JSON object in a ```json code block, nothing else, no comments inside the JSON.\n"
			. "- Use only the functions of the PHP and JavaScript reference below; a function that is not listed does not exist.\n"
			. "- Use only tables and columns of the table list, or columns the form creates (objects and form.columns). Lookups and sub-forms refer to existing forms by code, or to forms of the same app.\n"
			. "- Follow the example forms for the shape of the JSON (names of keys, object types, formats).\n"
			. ($full ? '' : "- The function reference is compact: one line per function; ask the user for the full context file when you need parameters or examples of a function.\n");
	}

	/** The bundled example forms (the sample application's customers and orders forms). */
	private static function examples(): string
	{
		$f = NU_ROOT . '/install/ai-examples.json';
		$j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
		$forms = is_array($j['forms'] ?? null) ? $j['forms'] : [];
		if (!$forms) return '(none)';
		$out = "Complete forms in the request format: a list + record form, an order form with a sub-form and lookups, and the sub-form itself.\n";
		foreach ($forms as $spec) {
			$out .= "\n### " . ($spec['form']['description'] ?? $spec['form']['code'] ?? '') . "\n\n```json\n" . self::json($spec) . "\n```\n";
		}
		return $out;
	}

	/** JSON indented by two spaces (PHP's pretty print uses four – a third of the examples' size). */
	private static function json(array $v): string
	{
		$j = (string)json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return (string)preg_replace_callback('/^( +)/m', static fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $j);
	}

	/** Hash variables with their English description. */
	private static function hashText(): string
	{
		static $en = null;
		$en ??= require NU_ROOT . '/lang/en.php';
		$out = "Written as #NAME# in SQL, PHP and JavaScript code; replaced before the code runs. Every field of the record is also a hash variable (#cus_name#), and so is every property set with nuSetProperty.\n";
		foreach (NuHash::CATALOG as $g => $names) {
			$out .= "\n### " . ($en['hv_group_' . $g] ?? $g) . "\n\n";
			foreach ($names as $n) $out .= '- `#' . $n . '#` – ' . ($en['hv_' . strtolower($n)] ?? '') . "\n";
		}
		return $out;
	}

	/** Guide pages of the help as Markdown. */
	private static function guide(bool $full): string
	{
		$out = '';
		foreach (array_merge(self::PAGES, $full ? self::FULL_PAGES : []) as $p) {
			$f = NU_ROOT . '/docs/pages/' . $p . '.htm';
			if (is_file($f)) $out .= self::htmlToMd((string)file_get_contents($f)) . "\n\n";
		}
		return trim($out) ?: '(none)';
	}

	/** The function reference of one language (php | js). */
	public static function functions(string $lang, bool $full): string
	{
		static $ref = null;
		$ref ??= json_decode((string)file_get_contents(NU_ROOT . '/docs/nunext-functions.json'), true);
		$part = $ref[$lang] ?? ['groups' => [], 'items' => []];
		$titles = [];
		foreach ($part['groups'] as $g) $titles[$g['id']] = $g['title'];
		$by = [];
		foreach ($part['items'] as $it) $by[$it['g'] ?? ''][] = $it;
		$out = $lang === 'php' ? "PHP of procedures and the form's PHP hooks (BB, BE, BS, AS, BD, AD).\n" : "JavaScript of the form (list and record), object events and procedures' JavaScript.\n";
		foreach ($titles + array_fill_keys(array_keys($by), '') as $gid => $title) {
			if (empty($by[$gid]) || (!$full && in_array($gid, self::SKIP_COMPACT[$lang] ?? [], true))) continue;
			$out .= "\n### " . ($title !== '' ? $title : $gid) . "\n\n";
			foreach ($by[$gid] as $it) $out .= self::fnEntry($it, $full || in_array($it['n'] ?? '', self::TOP, true), $full);
		}
		return $out;
	}

	private static function fnEntry(array $it, bool $example, bool $full): string
	{
		$d = trim((string)($it['d'] ?? ''));
		if (!$full && preg_match('/^(.{20,}?[.!?])(\s|$)/u', $d, $m)) $d = $m[1];
		$kind = ($it['k'] ?? '') === 'hook' ? ' (hook – a function your code defines)' : (($it['k'] ?? '') === 'method' ? ' (method)' : '');
		$line = '- `' . ($it['s'] ?? $it['n']) . '`' . $kind . ' – ' . $d . "\n";
		if (!$example) return $line;
		if ($full) {
			foreach ((array)($it['p'] ?? []) as $p => $txt) $line .= '  - `' . $p . '`: ' . $txt . "\n";
			if (!empty($it['r'])) $line .= '  - returns: ' . $it['r'] . "\n";
		}
		if (!empty($it['e'])) $line .= "  ```\n  " . str_replace("\n", "\n  ", (string)$it['e']) . "\n  ```\n";
		if ($full && !empty($it['x'])) $line .= '  result: `' . $it['x'] . "`\n";
		return $line;
	}

	/** A help page (one <section>, h2 / h3, p, ul / ol, table, pre, code) as Markdown; figures and source boxes left out. */
	public static function htmlToMd(string $html): string
	{
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR);
		libxml_clear_errors();
		$root = $dom->getElementsByTagName('div')->item(0);
		return $root ? trim((string)preg_replace("/\n{3,}/", "\n\n", self::mdBlock($root))) : '';
	}

	private static function mdBlock(DOMNode $n): string
	{
		$out = '';
		foreach ($n->childNodes as $c) {
			if ($c instanceof DOMText) { $t = trim($c->textContent); if ($t !== '') $out .= $t . "\n\n"; continue; }
			if (!$c instanceof DOMElement) continue;
			$tag = strtolower($c->tagName);
			if (in_array($tag, ['figure', 'svg', 'script', 'style', 'details', 'nav', 'button'], true) || self::skipped($c)) continue;
			switch ($tag) {
				case 'h1': case 'h2': $out .= '### ' . self::mdInline($c) . "\n\n"; break;
				case 'h3': case 'h4': $out .= '#### ' . self::mdInline($c) . "\n\n"; break;
				case 'p': $t = self::mdInline($c); if ($t !== '') $out .= $t . "\n\n"; break;
				case 'pre': $out .= "```\n" . rtrim($c->textContent) . "\n```\n\n"; break;
				case 'ul': case 'ol':
					$i = 0;
					foreach ($c->childNodes as $li) if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') $out .= ($tag === 'ol' ? (++$i) . '. ' : '- ') . self::mdInline($li) . "\n";
					$out .= "\n";
					break;
				case 'table': $out .= self::mdTable($c) . "\n\n"; break;
				default: $out .= self::mdBlock($c);
			}
		}
		return $out;
	}

	private static function mdInline(DOMNode $n): string
	{
		$out = '';
		foreach ($n->childNodes as $c) {
			if ($c instanceof DOMText) { $out .= $c->textContent; continue; }
			if (!$c instanceof DOMElement) continue;
			$tag = strtolower($c->tagName);
			if (in_array($tag, ['svg', 'figure', 'script', 'style', 'button'], true) || self::skipped($c)) continue;
			if ($tag === 'code' || $tag === 'kbd') $out .= '`' . $c->textContent . '`';
			elseif ($tag === 'strong' || $tag === 'b') $out .= '**' . self::mdInline($c) . '**';
			elseif ($tag === 'br') $out .= ' ';
			elseif ($tag === 'ul' || $tag === 'ol') $out .= ' (' . implode('; ', array_map(static fn($li) => self::mdInline($li), iterator_to_array($c->getElementsByTagName('li')))) . ')';
			else $out .= self::mdInline($c);
		}
		return trim((string)preg_replace('/\s+/u', ' ', $out));
	}

	/** Help-only parts: "For: …" / "See also" lines, the source box, the Advanced badge. */
	private static function skipped(DOMElement $e): bool
	{
		return (bool)preg_match('/(^|\s)(doc-for|doc-see|doc-src|doc-adv)(\s|$)/', $e->getAttribute('class'));
	}

	private static function mdTable(DOMElement $t): string
	{
		$rows = [];
		foreach ($t->getElementsByTagName('tr') as $tr) {
			$cells = [];
			foreach ($tr->childNodes as $td) if ($td instanceof DOMElement && in_array(strtolower($td->tagName), ['td', 'th'], true)) $cells[] = str_replace('|', '\\|', self::mdInline($td));
			if ($cells) $rows[] = '| ' . implode(' | ', $cells) . ' |';
		}
		if (!$rows) return '';
		array_splice($rows, 1, 0, ['|' . str_repeat(' --- |', substr_count($rows[0], ' | ') + 1)]);
		return implode("\n", $rows);
	}
}
