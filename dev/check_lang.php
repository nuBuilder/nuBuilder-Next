<?php
/*
 * nuBuilder Next – check the eleven language files against lang/en.php (run from the nunext folder):  php dev/check_lang.php
 * Reports keys missing in a language, keys that only a language has, and texts whose number of %s / %d placeholders differs
 * (t() uses sprintf – a missing %s cuts the value, an extra one throws). Exit code 1 when something is wrong.
 */
define('NU_ROOT', dirname(__DIR__));                // the language files only load inside nuBuilder Next
$dir = NU_ROOT . '/lang';
$en = require $dir . '/en.php';
$count = static fn(string $s) => preg_match_all('/%(?:\d+\$)?[sdfu]/', str_replace('%%', '', $s));
$bad = 0;
foreach (glob($dir . '/*.php') as $f) {
	$code = basename($f, '.php');
	if ($code === 'en' || str_contains($code, '-')) continue;   // en-AU / en-US / de-CH: overlays, checked by dev/lang_variants.php --check
	$l = require $f;
	$missing = array_diff_key($en, $l);
	$extra = array_diff_key($l, $en);
	$ph = [];
	foreach ($en as $k => $v) if (isset($l[$k]) && $count((string)$v) !== $count((string)$l[$k])) $ph[] = $k;
	if ($missing || $extra || $ph) {
		$bad++;
		echo "$code:", $missing ? ' missing ' . implode(', ', array_keys($missing)) : '', $extra ? ' | only here ' . implode(', ', array_keys($extra)) : '', $ph ? ' | placeholders differ ' . implode(', ', $ph) : '', "\n";
	}
}
// keys written twice in one file: PHP keeps the last one silently (0.8.887, DeepSource PHP-W1000)
foreach (glob($dir . '/*.php') as $f) {
	$t = token_get_all((string)file_get_contents($f));
	$seen = [];
	foreach ($t as $i => $x) {
		if (!is_array($x) || $x[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
		$j = $i + 1;
		while (isset($t[$j]) && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) $j++;
		if (!isset($t[$j]) || !is_array($t[$j]) || $t[$j][0] !== T_DOUBLE_ARROW) continue;
		if (isset($seen[$x[1]])) { $bad++; echo basename($f), ": key $x[1] twice (lines {$seen[$x[1]]} and $x[2])\n"; }
		$seen[$x[1]] = $x[2];
	}
}
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lang_variants.php') . ' --check 2>&1', $vo, $vc);   // language variants
if ($vc !== 0) { $bad++; echo implode("\n", $vo), "\n"; }
echo $bad ? "$bad language file(s) need work\n" : count($en) . " keys – all languages complete (language variants ok)\n";
exit($bad ? 1 : 0);
