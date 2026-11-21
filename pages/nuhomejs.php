<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* ?p=homejs&v=<hash>[&u=1] – the JavaScript of the start page hook nu_home (u=1: nu_home_user) as a file (core/nuhomehook.php). */
NuHomeHook::serveJs();
