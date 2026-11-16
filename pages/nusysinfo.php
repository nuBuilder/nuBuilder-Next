<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* System info (0.8.1237, core/nusysinfo.php): JSON for the dialog of the user menu – nuBuilder Next, server, database. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!NuSysInfo::allowed()) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
echo json_encode(['ok' => true, 'sections' => NuSysInfo::sections()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
