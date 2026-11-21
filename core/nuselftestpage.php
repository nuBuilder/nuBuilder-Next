<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuselftest.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSelftestPage
{
	/** The POST actions of pages/nuselftest.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		if (nu_is_post() && nu_post('action') === 'run') {
			$groups = array_values(array_intersect(array_map('strval', (array)($_POST['groups'] ?? [])), NuSelfTest::GROUPS));
			$res = NuSelfTest::run($groups);
			if (nu_post('format') === 'json') {
				header('Content-Type: application/json; charset=utf-8');
				exit(json_encode(['ok' => !in_array('fail', array_column($res, 'status'), true), 'results' => $res], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
			}
			$_SESSION[NuSess::SELFTEST] = ['at' => time(), 'groups' => $groups ?: NuSelfTest::GROUPS, 'results' => $res];
			nu_redirect(nu_url('selftest'));
		}
		return [];
	}
}
