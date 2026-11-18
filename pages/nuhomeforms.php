<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* Home forms of the access levels (0.8.1264, core/nuhomeforms.php): JSON for the panel next to the Home button – globeadmin only. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!NuHomeForms::allowed()) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
echo json_encode(['ok' => true] + NuHomeForms::data() + ['texts' => NuHomeForms::texts(), 'tiles_url' => nu_url('home', ['for' => NuHome::ALL_LEVELS]), 'levels_url' => nu_url('access')],
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
