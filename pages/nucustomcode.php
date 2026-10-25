<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* ?p=customcode&t=js|css&v=<hash> – Global JS & CSS of Admin tools → Custom code as a file (0.8.523, core/nucustomcode.php). */
NuCustomCode::serve();
