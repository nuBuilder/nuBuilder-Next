<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Search list (0.8.321) for Ctrl+K and the start page search – globeadmin only (core/nupalette.php). GET, JSON.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!NuAdmin::any()) {
	http_response_code(403);
	exit(json_encode(['ok' => false]));
}
exit(json_encode(['ok' => true, 'items' => NuPalette::items()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
