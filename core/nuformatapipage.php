<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nuformatapi.php (code review R7: a page defines no global function). */
final class NuFormatApiPage
{
	public static function out(array $d, int $status = 200): never
	{
		nu_json($d, $status);
	}
}
