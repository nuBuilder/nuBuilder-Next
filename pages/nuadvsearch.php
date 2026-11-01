<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * "My searches" of the advanced search (0.8.719, core/form/nuadvsearch.php): POST JSON {action: save | delete, f: form id, name, adv, id}
 * from assets/js/nu/nuadvsearch.js (X-CSRF-Token) → {ok, saved: [{id, name, adv}], error}. Only for a form / system list (f = list:<key>) the user may open.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$in = json_decode((string)file_get_contents('php://input'), true);
$in = is_array($in) ? $in : $_POST;
$form = is_string($in['f'] ?? null) ? $in['f'] : '';
$out = static function (array $d): never { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
// f = a form id, or list:<key> for a system list (0.8.769) – the user must be allowed to open it
$okTarget = false;
if (str_starts_with($form, 'list:')) {
	$lk = substr($form, 5);
	if (in_array($lk, NuListDefs::LISTS, true)) { $L = NuListView::def($lk); $okTarget = !empty($L['public']) || NuListView::mayOpen($L); }
} else {
	$okTarget = $form !== '' && isset(nu_forms()[$form]) && NuAcl::can($form);
}
if (!nu_is_post() || !$okTarget) { http_response_code(403); $out(['ok' => false, 'error' => t('err_forbidden')]); }
$action = (string)($in['action'] ?? '');
$err = '';
if ($action === 'save') $err = NuAdvSearch::save($form, (string)($in['name'] ?? ''), NuAdvSearch::parse($in['adv'] ?? ''));
elseif ($action === 'delete') NuAdvSearch::delete($form, (string)($in['id'] ?? ''));
else $err = t('err_invalid');
$out(['ok' => $err === '', 'error' => $err, 'saved' => NuAdvSearch::saved($form)]);
