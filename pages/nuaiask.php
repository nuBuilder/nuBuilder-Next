<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * "Ask AI" (0.8.1099, core/nuaiprovider.php, assets/js/nuaiask.js): the AI dialogues send their prompt to a configured AI service.
 *   unlock  provider, password                          → {ok, error}             a password-protected configuration, once per session
 *   ask     provider, feature, prompt[, prev, errors[]] → {ok, text, error, note} prev + errors = the automatic correction round
 * The answer is only handed to the dialogue, which checks it as a pasted answer – nothing is applied here.
 */
header('Content-Type: application/json; charset=utf-8');
$out = static function (array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
if (!nu_is_post()) $out(['ok' => false, 'error' => 'POST'], 405);
$feature = nu_post('feature');
if (!NuAiProvider::featureAllowed($feature)) $out(['ok' => false, 'error' => t('aip_err_not_allowed')], 403);
$id = nu_post('provider');
$action = nu_post('action');

if ($action === 'unlock') {
	$e = NuAiProvider::unlock($id, (string)($_POST['password'] ?? ''));
	$out(['ok' => $e === '', 'error' => $e]);
}
if ($action === 'ask') {
	$messages = [['user', (string)($_POST['prompt'] ?? '')]];
	$prev = (string)($_POST['prev'] ?? '');
	$errors = array_values(array_filter(array_map('strval', (array)($_POST['errors'] ?? [])), 'strlen'));
	if ($prev !== '' && $errors) { $messages[] = ['assistant', $prev]; $messages[] = ['user', NuAiProvider::fixMessage($errors)]; }
	$d = NuAiProvider::get($id);
	session_write_close();                                            // a long request must not block the user's other pages
	nu_time_limit(($d['timeout'] ?? 180) + 30);
	$r = NuAiProvider::ask($id, $messages, $feature);
	$out(['ok' => $r['ok'], 'text' => $r['text'], 'error' => $r['ok'] ? '' : $r['error'], 'note' => $r['ok'] ? $r['error'] : '', 'tokens' => $r['in'] + $r['out'], 'ms' => $r['ms']]);
}
$out(['ok' => false, 'error' => '?'], 400);
