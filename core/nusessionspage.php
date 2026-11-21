<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nusessions.php (code review R7: a page defines no global function). */
final class NuSessionsPage
{
	public static function ago(int $ts): string
	{
		$d = max(0, time() - $ts);
		if ($d < 60) return t('sess_ago_s', $d);
		if ($d < 3600) return t('sess_ago_m', intdiv($d, 60));
		return t('sess_ago_h', intdiv($d, 3600), intdiv($d % 3600, 60));
	}
}
