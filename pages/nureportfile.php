<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Download link of a report made through the REST API (0.8.340): index.php?p=reportfile&t=<token>[&dl=1] – no login,
 * the token is the permission (core/nureportapi.php). dl=1: as attachment instead of inline.
 */
NuReportApi::download(is_string($_GET['t'] ?? null) ? (string)$_GET['t'] : '');
