<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Output of a procedure run with nuRunPHP (nuBuilder Forte: core/nurunphp.php?i=<id>). formapi (action php, window) runs the
 * procedure – a file it sends is saved by the form page, no window – and answers a token that stands for the call (procedure, form,
 * hash variables, the output). The window or the iframe nuRunPHP names opens this page with it: the first view shows that output;
 * a reload or a link with more parameters ($_SERVER['REQUEST_URI'] . '&x=1' → $_GET['x']) runs the procedure again, as in Forte.
 */

$run = NuRunPhp::fromToken((string)($_GET['i'] ?? ''));
if (!$run) {
	http_response_code(410);
	nu_fatal(t('runphp_gone'), t('runphp_gone_hint'), true);
}
$meta = NuFormMeta::load($run['form']);
if (!$meta || !NuAcl::can($meta->id)) {
	http_response_code(403);
	nu_fatal(t('err_forbidden'), '', true);
}
NuTranslate::activate();

// the output is the procedure's own HTML (inline scripts and styles, like the form pages it was opened from)
$inc = NuIncludes::cspSources();
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline'{$inc['style']}; script-src 'self' 'unsafe-inline' 'unsafe-eval'{$inc['script']}; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'self' data: blob:; frame-ancestors 'self'; form-action 'self'; base-uri 'none'");
header('X-Frame-Options: SAMEORIGIN');
header('Content-Type: text/html; charset=utf-8');

$first = array_diff(array_keys($_GET), ['p', 'i']) === [] ? NuRunPhp::takeOutput((string)$_GET['i']) : null;
// Without an icon of its own the browser shows the server's /favicon.ico (often another program's).
$withIcon = static function (string $html): string {
	if (preg_match('~<link[^>]+rel=["\']?(?:shortcut )?icon~i', $html)) return $html;
	$tag = NuBranding::faviconTag();
	if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
		$at = $m[0][1] + strlen($m[0][0]);
		return substr($html, 0, $at) . $tag . substr($html, $at);
	}
	return $tag . $html;
};
if ($first !== null) {
	echo $withIcon($first);
	return;
}
NuPhp::reset();
$r = NuPhp::procedure($run['code'], $run['hash']);
$errors = !$r['ok'] ? [(string)($r['error'] ?? t('err_unexpected'))] : array_map(static fn($e) => (string)($e['message'] ?? ''), NuPhp::$errors);
$errors = array_values(array_filter($errors, static fn($m) => trim(strip_tags($m)) !== ''));
$out = NuPhp::$output;
$hasHead = preg_match('~<head\\b~i', $out) === 1;
if (!$hasHead && !preg_match('~<link[^>]+rel=["\']?(?:shortcut )?icon~i', $out)) echo NuBranding::faviconTag();
if ($errors) {
	echo '<div class="nuRunPhpErr" style="font:14px system-ui,sans-serif;color:#842029;background:#f8d7da;border:1px solid #f5c2c7;border-radius:6px;margin:.5rem;padding:.75rem">';
	foreach ($errors as $m) echo '<div>' . h(strip_tags($m)) . '</div>';
	echo '</div>';
}
echo $hasHead ? $withIcon($out) : $out;
