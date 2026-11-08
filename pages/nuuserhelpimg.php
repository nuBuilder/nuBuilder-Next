<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

// Pictures of the user help pages (0.8.1024, NuUserHelp::serveImage): signed-in users only; the name is the hash of the content.
NuUserHelp::serveImage((string)($_GET['f'] ?? ''));
