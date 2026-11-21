<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nucloner.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuClonerPage
{
	/** The POST actions of pages/nucloner.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$row = $nuIn['row'] ?? null; $src = $nuIn['src'] ?? null; $def = $nuIn['def'] ?? null; $in = $nuIn['in'] ?? null;
		if (nu_is_post()) {
			$in = [
				'source' => nu_post('source'),
				'dest_mode' => nu_post('dest_mode') === 'existing' ? 'existing' : 'new', 'dest' => nu_post('dest'), 'code' => nu_post('code'), 'description' => nu_post('description'),
				'tabs' => array_map('strval', (array)($_POST['tabs'] ?? [])), 'objects' => !empty($_POST['objects']),
				'subforms' => !empty($_POST['subforms']), 'subform_ids' => array_map('strval', (array)($_POST['subform_ids'] ?? [])),
				'iframes' => !empty($_POST['iframes']), 'iframe_ids' => array_map('strval', (array)($_POST['iframe_ids'] ?? [])),
				'access' => !empty($_POST['access']), 'dump' => !empty($_POST['dump']), 'keep_pks' => !empty($_POST['keep_pks']), 'replace' => !empty($_POST['replace']),
				'notes' => (string)($_POST['notes'] ?? ''), 'target' => nu_post('target') === 'sqlsrv' ? 'sqlsrv' : 'mysql',
			];
		} else {
			$in = $row || $src ? NuClonerConfig::toInput($row ?? $src, $def) : $def;
		}
		return compact('in', 'row', 'src');
	}
}
