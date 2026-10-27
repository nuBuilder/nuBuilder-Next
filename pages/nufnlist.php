<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Function list for the code editors (0.8.593, assets/js/nu/nucodetools.js – Insert menu and suggestions while typing):
 * GET ?p=fnlist&lang=php|js → the entries of docs/nunext-functions.json (NuFnRef) with the help link of each.
 * Signed-in users only (the editors are for designers / admins; the reference itself is not secret). Cached by the browser for 10 minutes.
 */
session_write_close();
$lang = ($_GET['lang'] ?? '') === 'js' ? 'js' : 'php';
$ref = NuFnRef::load()[$lang] ?? ['groups' => [], 'items' => []];
$groups = [];
foreach ($ref['groups'] ?? [] as $g) if (is_array($g)) $groups[(string)($g['id'] ?? '')] = (string)($g['title'] ?? '');
$help = nu_url('help', ['tab' => 'ref']);
$items = [];
foreach ($ref['items'] ?? [] as $it) {
	if (!is_array($it) || !isset($it['n'], $it['s'])) continue;
	$items[] = ['n' => (string)$it['n'], 's' => (string)$it['s'], 'd' => (string)($it['d'] ?? ''), 'r' => (string)($it['r'] ?? ''),
		'e' => (string)($it['e'] ?? ''), 'x' => (string)($it['x'] ?? ''), 'p' => is_array($it['p'] ?? null) ? $it['p'] : (object)[],
		'g' => (string)($it['g'] ?? ''), 'o' => (string)($it['o'] ?? ''), 'k' => (string)($it['k'] ?? 'fn'),
		'h' => $help . '#' . NuFnRef::anchor($lang, (string)$it['n'])];
}
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');   // a page that opens several editors asks once
exit((string)json_encode(['lang' => $lang, 'groups' => $groups, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
