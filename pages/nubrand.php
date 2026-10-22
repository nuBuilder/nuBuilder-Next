<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Branding files of this installation (0.8.297), no login needed – the sign-in pages use them too:
 *   ?p=brand&a=css&v=<version>   generated stylesheet (accent colour, environment label)
 *   ?p=brand&a=logo|logodark     own logo from zzzzsys_file (nuBuilder Next's logo when it was deleted)
 */
session_write_close();
NuBranding::serve((string)($_GET['a'] ?? ''));
