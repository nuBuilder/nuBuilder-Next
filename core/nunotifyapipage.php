<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nunotifyapi.php (code review R7: a page defines no global function). */
final class NuNotifyApiPage
{
	public static function out(array $d, int $status = 200): never
	{
		nu_json($d, $status);
	}

	/** The POST actions of pages/nunotifyapi.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		if (nu_is_post()) {
			$in = json_decode((string)file_get_contents('php://input'), true);
			$in = is_array($in) ? $in : [];
			$a = (string)($in['action'] ?? '');
			if ($a === 'readall') NuNotify::readAll();
			elseif (!NuNotify::setState((string)($in['id'] ?? ''), $a)) NuNotifyApiPage::out(['ok' => false, 'error' => t('err_not_found')], 404);
		}
		return [];
	}
}
