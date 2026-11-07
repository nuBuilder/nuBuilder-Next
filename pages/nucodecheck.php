<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Syntax check of PHP code (and, with lang=sql, of SQL through the database – NuSqlCheck, 0.8.999) for the code editors (0.8.781, assets/js/nu/nucodetools.js – JavaScript, CSS and HTML are checked in the
 * browser): POST ?p=codecheck {code, open} → {ok, errors: [{line, col, msg}]}. token_get_all with TOKEN_PARSE parses the code – nothing
 * runs, nothing is stored (action=check). Procedure / event code is kept without "<?php" (open = 0): the check puts it in front and counts lines from 1.
 * Signed-in users only (index.php), CSRF checked by index.php; read-only for demo visitors (NuDemo::READ_POSTS).
 */
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$code = nu_post('code');
if (strlen($code) > 2_000_000) exit((string)json_encode(['ok' => false, 'errors' => [], 'error' => 'too_long']));
// SQL (0.8.999): parsed by the database without running it – form designers only (it tells whether tables / columns exist)
if (nu_post('lang') === 'sql') {
	$errs = NuSqlCheck::allowed() ? NuSqlCheck::check($code) : null;
	exit((string)json_encode($errs === null ? ['ok' => true, 'skip' => true, 'errors' => []] : ['ok' => true, 'errors' => $errs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}
$open = nu_post('open') === '1' || preg_match('/^\s*<\?php/i', $code) === 1;
$src = $open ? $code : "<?php\n" . $code;
$errors = [];
try {
	token_get_all($src, TOKEN_PARSE);
} catch (ParseError $e) {
	$errors[] = ['line' => max(1, $e->getLine() - ($open ? 0 : 1)), 'col' => 0, 'msg' => $e->getMessage()];
} catch (Throwable $e) {
	$errors[] = ['line' => 1, 'col' => 0, 'msg' => $e->getMessage()];
}
exit((string)json_encode(['ok' => true, 'errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
