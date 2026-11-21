<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuappcloner.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuAppclonerPage
{
	/** The POST actions of pages/nuappcloner.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$def = $nuIn['def'] ?? null;
		$srcCharset = $nuIn['srcCharset'] ?? null;
		$srcCollation = $nuIn['srcCollation'] ?? null;
		$isMs = $nuIn['isMs'] ?? null;
		$nubPath = $nuIn['nubPath'] ?? null;
		$row = $nuIn['row'] ?? null;
		$src = $nuIn['src'] ?? null;
		$cfg = $nuIn['cfg'] ?? null;
		$defPort = $nuIn['defPort'] ?? null;
		$in = $nuIn['in'] ?? null;
		if (nu_is_post()) {
			$in = [
				'code' => nu_post('code'), 'description' => nu_post('description'),
				'same_login' => !empty($_POST['same_login']),
				'host' => nu_post('host') ?: $def['host'], 'port' => nu_post('port') ?: $def['port'],
				'user' => nu_post('user') ?: $def['user'], 'password' => (string)($_POST['password'] ?? ''),
				'db' => nu_post('db') ?: $def['db'], 'create_db' => !empty($_POST['create_db']),
				'db_mode' => in_array(nu_post('db_mode'), ['fail', 'clear', 'add'], true) ? nu_post('db_mode') : 'fail',
				'charset' => nu_post('charset') ?: $srcCharset, 'collation' => nu_post('collation') ?: $srcCollation,
				'parts' => array_values(array_intersect(array_keys(NuAppCloner::PARTS), (array)($_POST['parts'] ?? []))),
				'insert' => !$isMs && in_array(nu_post('insert'), ['INSERT', 'INSERT IGNORE', 'REPLACE'], true) ? nu_post('insert') : 'INSERT',
				'skip_sessions' => !empty($_POST['skip_sessions']),
				'tables_all' => !empty($_POST['tables_all']), 'tables' => array_map('strval', (array)($_POST['tables'] ?? [])),
				'files' => !empty($_POST['files']),
				'source_path' => nu_post('source_path') ?: $nubPath, 'target_path' => nu_post('target_path') ?: $def['target_path'],
				'file_mode' => in_array(nu_post('file_mode'), ['fail', 'clear', 'merge'], true) ? nu_post('file_mode') : 'fail',
				'excludes' => (string)($_POST['excludes'] ?? ''),
				'stored_password' => ($row ?? $src) && trim((string)($row ?? $src)['cla_target_password']) !== '',
			];
		} else {
			$in = $row || $src ? NuAppClonerConfig::toInput($row ?? $src, $def, $cfg, $defPort) : $def;
			if ($src) $in['code'] = NuAppClonerConfig::cloneCode((string)$src['cla_code']);
		}
		return compact('in', 'def', 'isMs', 'row', 'src', 'cfg');
	}
}
