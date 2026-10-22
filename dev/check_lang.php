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
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/lang_variants.php') . ' --check 2>&1', $vo, $vc);   // language variants
if ($vc !== 0) { $bad++; echo implode("\n", $vo), "\n"; }
echo $bad ? "$bad language file(s) need work\n" : count($en) . " keys – all languages complete (language variants ok)\n";
exit($bad ? 1 : 0);
