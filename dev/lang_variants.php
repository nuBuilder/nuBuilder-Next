<?php
declare(strict_types=1);

/*
 * English variants (0.8.314). lang/en.php is British English (the base, en-GB). This tool builds lang/en-US.php – only the texts
 * whose American spelling differs (colour → color, customise → customize, cancelled → canceled …) – from lang/en.php and a word list.
 * lang/en-AU.php (Australian English) is kept by hand: Australia spells like the UK, it only holds the rare texts that differ.
 *
 * German (0.8.315): lang/de.php is German German (ß, „quotes“); lang/de-CH.php (Swiss German: ss, «quotes») is generated the same
 * way – ß → ss, „“ → «», ‚‘ → ‹›. --de / --de-write find and change Swiss spellings in lang/de.php (word list CH_DE_WORDS).
 *
 *   php dev/lang_variants.php            build lang/en-US.php and lang/de-CH.php (dev/release.py runs this before its checks)
 *   php dev/lang_variants.php --check    exit 1 when lang/en-US.php is not up to date or lang/en.php holds American spellings
 *   php dev/lang_variants.php --uk       print the American spellings found in lang/en.php (to change them to British)
 *   php dev/lang_variants.php --uk-write change them in lang/en.php
 *
 * Exceptions: KEEP_KEYS (texts never changed) and PROTECT (words that stay as they are, e.g. the HTTP header "Authorization").
 * Words inside identifiers ($nuConfigEnvironmentColor, color-scheme, nuColor) are never touched.
 */

const ROOT = __DIR__ . '/..';

/** Texts that are never changed (key => reason). */
const KEEP_KEYS = [];

/** Exact words that stay as written in both directions (case-sensitive). */
const PROTECT = ['Authorization'];

/** British => American, whole words (lower case; capitalised and upper-case forms follow). */
function uk_us_words(): array
{
	$w = [];
	$pairs = [
		'colour' => 'color', 'colours' => 'colors', 'coloured' => 'colored', 'colouring' => 'coloring', 'colourful' => 'colorful', 'colourless' => 'colorless',
		'centre' => 'center', 'centres' => 'centers', 'centred' => 'centered', 'centring' => 'centering',
		'behaviour' => 'behavior', 'behaviours' => 'behaviors',
		'favourite' => 'favorite', 'favourites' => 'favorites', 'favour' => 'favor', 'favours' => 'favors',
		'honour' => 'honor', 'neighbour' => 'neighbor', 'neighbours' => 'neighbors', 'labour' => 'labor', 'humour' => 'humor', 'rumour' => 'rumor',
		'grey' => 'gray', 'greyed' => 'grayed', 'greys' => 'grays',
		'catalogue' => 'catalog', 'catalogues' => 'catalogs',
		'dialogue' => 'dialog', 'dialogues' => 'dialogs',
		'licence' => 'license', 'licences' => 'licenses', 'defence' => 'defense', 'offence' => 'offense',
		'analyse' => 'analyze', 'analysed' => 'analyzed', 'analysing' => 'analyzing', 'paralyse' => 'paralyze',
		'cancelled' => 'canceled', 'cancelling' => 'canceling',
		'labelled' => 'labeled', 'labelling' => 'labeling', 'modelled' => 'modeled', 'modelling' => 'modeling',
		'travelled' => 'traveled', 'travelling' => 'traveling', 'levelled' => 'leveled', 'signalled' => 'signaled', 'totalled' => 'totaled',
		'enrolment' => 'enrollment', 'enrolments' => 'enrollments', 'fulfil' => 'fulfill', 'fulfilment' => 'fulfillment', 'fulfils' => 'fulfills',
		'metre' => 'meter', 'metres' => 'meters', 'litre' => 'liter', 'litres' => 'liters',
		'judgement' => 'judgment', 'ageing' => 'aging', 'artefact' => 'artifact', 'artefacts' => 'artifacts',
		'emphasise' => 'emphasize', 'emphasised' => 'emphasized', 'emphasises' => 'emphasizes', 'emphasising' => 'emphasizing',
	];
	foreach ($pairs as $uk => $us) $w[$uk] = $us;
	// -ise / -isation verbs: stem + is + ending → stem + iz + ending
	$stems = ['organ', 'reorgan', 'author', 'recogn', 'custom', 'initial', 'normal', 'synchron', 'optim', 'summar', 'minim', 'maxim', 'serial',
		'deserial', 'categor', 'visual', 'priorit', 'final', 'sanit', 'personal', 'special', 'standard', 'util', 'apolog', 'real', 'local',
		'capital', 'critic', 'memor', 'random', 'parameter', 'token', 'central', 'stabil', 'general', 'character', 'modern', 'neutral',
		'symbol', 'harmon', 'synthes', 'familiar', 'priorit', 'internation', 'monet', 'penal', 'legal', 'digit', 'sympath', 'subsid', 'vapor'];
	foreach (array_unique($stems) as $st) {
		foreach (['e', 'ed', 'es', 'ing', 'er', 'ers', 'ation', 'ations', 'able'] as $end) $w[$st . 'is' . $end] = $st . 'iz' . $end;
	}
	return $w;
}

/** Replaces whole words by the map, keeping the case pattern (word, Word, WORD); identifiers stay untouched. */
function convert(string $text, array $map): string
{
	return (string)preg_replace_callback('/(?<![\w$\-.\/])([A-Za-z]+)(?![\w\-(\/])/u', static function ($m) use ($map) {
		$word = $m[1];
		if (in_array($word, PROTECT, true)) return $word;
		$lower = strtolower($word);
		if (!isset($map[$lower])) return $word;
		$to = $map[$lower];
		if ($word === strtoupper($word) && strlen($word) > 1) return strtoupper($to);
		if ($word[0] === strtoupper($word[0])) return ucfirst($to);
		return $to;
	}, $text);
}

/** Swiss → German spellings (whole words; ß after a long vowel or diphthong – "muss", "dass", "Passwort" stay). */
const CH_DE_WORDS = [
	'ausser' => 'außer', 'ausserhalb' => 'außerhalb', 'ausserdem' => 'außerdem', 'äusserst' => 'äußerst',
	'fuss' => 'fuß', 'füsse' => 'füße', 'fussbereich' => 'fußbereich', 'berichtsfuss' => 'berichtsfuß', 'seitenfuss' => 'seitenfuß',
	'gruppenfuss' => 'gruppenfuß', 'gruppenfüsse' => 'gruppenfüße', 'fusszeile' => 'fußzeile', 'fusszeilen' => 'fußzeilen',
	'gross' => 'groß', 'grosse' => 'große', 'grossen' => 'großen', 'grosser' => 'großer', 'grosses' => 'großes', 'grossbuchstaben' => 'großbuchstaben',
	'grossschreibung' => 'großschreibung', 'grösse' => 'größe', 'grössen' => 'größen', 'grösser' => 'größer', 'grösseren' => 'größeren',
	'grössere' => 'größere', 'grösste' => 'größte', 'grössten' => 'größten', 'dateigrösse' => 'dateigröße', 'schriftgrösse' => 'schriftgröße',
	'schliessen' => 'schließen', 'schliesst' => 'schließt', 'geschlossen' => 'geschlossen', 'einschliesslich' => 'einschließlich',
	'ausschliesslich' => 'ausschließlich', 'schliesslich' => 'schließlich', 'abschliessen' => 'abschließen', 'anschliessend' => 'anschließend',
	'weiss' => 'weiß', 'heisst' => 'heißt', 'heissen' => 'heißen', 'liess' => 'ließ', 'liessen' => 'ließen',
	'regelmässig' => 'regelmäßig', 'standardmässig' => 'standardmäßig', 'gemäss' => 'gemäß', 'mässig' => 'mäßig', 'zweckmässig' => 'zweckmäßig',
	'strasse' => 'straße', 'strassen' => 'straßen', 'mass' => 'maß', 'masse' => 'maße', 'massnahme' => 'maßnahme', 'massnahmen' => 'maßnahmen',
	'bloss' => 'bloß', 'süss' => 'süß', 'gruss' => 'gruß', 'grüsse' => 'grüße', 'grüssen' => 'grüßen', 'begrüssung' => 'begrüßung',
	'fliessen' => 'fließen', 'fliesst' => 'fließt', 'giessen' => 'gießen', 'stossen' => 'stoßen', 'spass' => 'spaß', 'aussen' => 'außen',
];

/** Replaces whole German words by the map, keeping a capital first letter. */
function convert_de(string $text, array $map): string
{
	return (string)preg_replace_callback('/(?<![\p{L}\d$_\-.\/])(\p{L}+)(?![\p{L}\d_\-(\/])/u', static function ($m) use ($map) {
		$word = $m[1];
		$lower = mb_strtolower($word);
		if (!isset($map[$lower])) return $word;
		$to = $map[$lower];
		return mb_substr($word, 0, 1) !== mb_strtolower(mb_substr($word, 0, 1)) ? mb_strtoupper(mb_substr($to, 0, 1)) . mb_substr($to, 1) : $to;
	}, $text);
}

/** German text → Swiss German: ß → ss, „…“ → «…», ‚…‘ → ‹…›. */
function de_to_ch(string $text): string
{
	return strtr($text, ['ß' => 'ss', 'ẞ' => 'SS', '„' => '«', '“' => '»', '‚' => '‹', '‘' => '›']);
}

/** Swiss spellings in a German text → German (for converting lang/de.php once and for --check). */
function ch_to_de(string $text): string
{
	return strtr(convert_de($text, CH_DE_WORDS), ['«' => '„', '»' => '“', '‹' => '‚', '›' => '‘']);
}

function build_ch(array $de): string
{
	$out = [];
	foreach ($de as $k => $v) {
		if (!is_string($v)) continue;
		$c = de_to_ch($v);
		if ($c !== $v) $out[$k] = $c;
	}
	$php = "<?php\ndeclare(strict_types=1);\ndefined('NU_ROOT') || exit;\n\n"
		. "// Swiss German (de-CH): only the texts that differ from lang/de.php (German German) – ß → ss, „“ → «».\n"
		. "// GENERATED by dev/lang_variants.php from lang/de.php – do not edit (dev/release.py runs it).\n"
		. "return [\n";
	foreach ($out as $k => $v) $php .= "\t" . q((string)$k) . ' => ' . q($v) . ",\n";
	return $php . "];\n";
}

function load(string $file): array
{
	defined('NU_ROOT') || define('NU_ROOT', ROOT);
	$a = require $file;
	return is_array($a) ? $a : [];
}

function q(string $s): string
{
	return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
}

function build_us(array $en): string
{
	$map = uk_us_words();
	$out = [];
	foreach ($en as $k => $v) {
		if (isset(KEEP_KEYS[$k]) || !is_string($v)) continue;
		$c = convert($v, $map);
		if ($c !== $v) $out[$k] = $c;
	}
	$php = "<?php\ndeclare(strict_types=1);\ndefined('NU_ROOT') || exit;\n\n"
		. "// American English (en-US): only the texts that differ from lang/en.php (British English).\n"
		. "// GENERATED by dev/lang_variants.php from lang/en.php – do not edit; change the word list there (dev/release.py runs it).\n"
		. "return [\n";
	foreach ($out as $k => $v) $php .= "\t" . q((string)$k) . ' => ' . q($v) . ",\n";
	return $php . "];\n";
}

$en = load(ROOT . '/lang/en.php');
$de = load(ROOT . '/lang/de.php');
$targetCh = ROOT . '/lang/de-CH.php';
$swiss = static function (array $de): array {                // texts of lang/de.php with Swiss spellings
	$f = [];
	foreach ($de as $k => $v) if (is_string($v) && ($c = ch_to_de($v)) !== $v) $f[$k] = [$v, $c];
	return $f;
};
$target = ROOT . '/lang/en-US.php';
$mode = $argv[1] ?? '';

if ($mode === '--de' || $mode === '--de-write') {
	$found = $swiss($de);
	if ($mode === '--de') {
		foreach ($found as $k => [$a, $b]) echo "$k\n  - $a\n  + $b\n";
		echo count($found) . " text(s) with Swiss spelling\n";
		exit(0);
	}
	$file = (string)file_get_contents(ROOT . '/lang/de.php');
	$n = 0;
	foreach ($found as $k => [$a, $b]) {
		$old = q((string)$k) . ' => ' . q($a);
		if (substr_count($file, $old) !== 1) { echo "not changed (write it by hand): $k\n"; continue; }
		$file = str_replace($old, q((string)$k) . ' => ' . q($b), $file);
		$n++;
	}
	file_put_contents(ROOT . '/lang/de.php', $file);
	echo "$n text(s) changed in lang/de.php\n";
	exit(0);
}

if ($mode === '--uk-write') {
	$us = array_flip(uk_us_words());
	$file = (string)file_get_contents(ROOT . '/lang/en.php');
	$n = 0;
	foreach ($en as $k => $v) {
		if (isset(KEEP_KEYS[$k]) || !is_string($v) || ($c = convert($v, $us)) === $v) continue;
		$old = q((string)$k) . ' => ' . q($v);
		if (substr_count($file, $old) !== 1) { echo "not changed (write it by hand): $k\n"; continue; }
		$file = str_replace($old, q((string)$k) . ' => ' . q($c), $file);
		$n++;
	}
	file_put_contents(ROOT . '/lang/en.php', $file);
	echo "$n text(s) changed in lang/en.php\n";
	exit(0);
}

if ($mode === '--uk' || $mode === '--check') {
	$us = array_flip(uk_us_words());
	$found = [];
	foreach ($en as $k => $v) {
		if (isset(KEEP_KEYS[$k]) || !is_string($v)) continue;
		$c = convert($v, $us);
		if ($c !== $v) $found[$k] = [$v, $c];
	}
	if ($mode === '--uk') {
		foreach ($found as $k => [$a, $b]) echo "$k\n  - $a\n  + $b\n";
		echo count($found) . " text(s) with American spelling\n";
		exit(0);
	}
	$errors = [];
	if ($found) $errors[] = 'lang/en.php has American spellings (British English is the base): ' . implode(', ', array_keys($found)) . ' – see php dev/lang_variants.php --uk';
	if (!is_file($target) || file_get_contents($target) !== build_us($en)) $errors[] = 'lang/en-US.php is not up to date – run php dev/lang_variants.php';
	if ($sw = $swiss($de)) $errors[] = 'lang/de.php has Swiss spellings (German German is the base): ' . implode(', ', array_keys($sw)) . ' – see php dev/lang_variants.php --de';
	if (!is_file($targetCh) || file_get_contents($targetCh) !== build_ch($de)) $errors[] = 'lang/de-CH.php is not up to date – run php dev/lang_variants.php';
	foreach (['en-US' => $en, 'en-AU' => $en, 'de-CH' => $de] as $var => $base) {   // overlays: known keys, same placeholders
		$f = ROOT . "/lang/$var.php";
		if (!is_file($f)) { $errors[] = "lang/$var.php is missing"; continue; }
		foreach (load($f) as $k => $v) {
			if (!isset($base[$k])) { $errors[] = "lang/$var.php: unknown key $k"; continue; }
			preg_match_all('/%[sd]/', (string)$base[$k], $a);
			preg_match_all('/%[sd]/', (string)$v, $b);
			if ($a[0] !== $b[0]) $errors[] = "lang/$var.php: placeholders of $k differ";
		}
	}
	if ($errors) { fwrite(STDERR, implode("\n", $errors) . "\n"); exit(1); }
	echo "Language variants: ok\n";
	exit(0);
}

foreach (['lang/en-US.php' => [$target, build_us($en)], 'lang/de-CH.php' => [$targetCh, build_ch($de)]] as $name => [$file, $php]) {
	$old = is_file($file) ? (string)file_get_contents($file) : '';
	if ($old !== $php) file_put_contents($file, $php);
	echo "$name: " . (substr_count($php, "\n") - 6) . ' text(s)' . ($old !== $php ? ' (written)' : ' (unchanged)') . "\n";
}
