<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

// installable app: manifest, icons, service worker, offline page – no login needed (core/nupwa.php)
NuPwa::serve((string)($_GET['a'] ?? ''));
