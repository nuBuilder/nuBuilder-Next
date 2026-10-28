<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Branding files of this installation (0.8.297), no login needed – the sign-in pages use them too:
 *   ?p=brand&a=css&v=<version>   generated stylesheet (accent colour, environment label)
 *   ?p=brand&a=logo|logodark     own logo from zzzzsys_file (nuBuilder Next's logo when it was deleted)
 *   ?p=brand&a=theme&t=<id>&v=<version>   stylesheet of a theme (0.8.632, NuTheme; t=draft: the editor's preview, from the session)
 */
session_write_close();
if (($_GET['a'] ?? '') === 'theme') NuTheme::serve(is_string($_GET['t'] ?? null) ? (string)$_GET['t'] : '');
NuBranding::serve((string)($_GET['a'] ?? ''));
