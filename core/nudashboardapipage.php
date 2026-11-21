<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nudashboardapi.php (code review R7: a page defines no global function). */
final class NuDashboardApiPage
{
	public static function out(array $data, int $status = 200): never
	{
		nu_json($data, $status);
	}
}
