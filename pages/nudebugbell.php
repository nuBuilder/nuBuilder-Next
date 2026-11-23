<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * The debug icon of the top bar (core/nudebugbell.php, assets/js/nudebugbell.js):
 *   GET            → {ok, n} – the number of new Debug results (does not keep an idle session alive: NU_PASSIVE, index.php)
 *   GET list=1     → {ok, n, items} – also the newest rows
 *   POST action=read (X-CSRF-Token) → marks everything as read → {ok, n: 0}
 */
if (!nu_is_post()) nu_session_release();
if (!NuDebugBell::allowed()) nu_json(['ok' => false, 'error' => 'forbidden'], 403);
if (nu_is_post()) {
	$in = json_decode((string)file_get_contents('php://input'), true);
	if (is_array($in) && ($in['action'] ?? '') === 'read') { NuDebugBell::markRead(); nu_json(['ok' => true, 'n' => 0]); }
	nu_json(['ok' => false, 'error' => 'action'], 400);
}
$out = ['ok' => true] + NuDebugBell::summary();
if (($_GET['list'] ?? '') === '1') $out['items'] = NuDebugBell::latest();
nu_json($out);
