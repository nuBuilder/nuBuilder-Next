<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * The bell (0.8.357, assets/js/nunotify.js – core/nunotify.php):
 *   GET                                  → {unread, items (latest 10, not hidden), unread_ids, confirm (still to confirm)} – does not keep
 *                                          an idle session alive (NU_PASSIVE, index.php)
 *   POST {action: read|unread|hide|unhide|confirm, id} / {action: readall} (X-CSRF-Token) → the same summary
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!nu_is_post()) session_write_close();                     // the check every minute holds no session lock

function nu_ntf_out(array $d, int $status = 200): never
{
	http_response_code($status);
	echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

if (nu_is_post()) {
	$in = json_decode((string)file_get_contents('php://input'), true);
	$in = is_array($in) ? $in : [];
	$a = (string)($in['action'] ?? '');
	if ($a === 'readall') NuNotify::readAll();
	elseif (!NuNotify::setState((string)($in['id'] ?? ''), $a)) nu_ntf_out(['ok' => false, 'error' => t('err_not_found')], 404);
}
nu_ntf_out(['ok' => true] + NuNotify::summary());
