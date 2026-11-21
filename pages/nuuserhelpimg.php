<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

// Pictures of the user help pages (NuUserHelp::serveImage): signed-in users only; the name is the hash of the content.
NuUserHelp::serveImage((string)($_GET['f'] ?? ''));
