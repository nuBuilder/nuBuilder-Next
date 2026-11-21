<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nudataexport.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuDataexportPage
{
	/** The POST actions of pages/nudataexport.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$json = $nuIn['json'] ?? null; $x = $nuIn['x'] ?? null;
		if (nu_is_post() && in_array(nu_post('action'), ['tables', 'columns', 'preview', 'count', 'check'], true)) {
			NuAdmin::require('dataexport', 'change');
			$action = nu_post('action');
			try {
				if ($action === 'tables') {
					$tables = [];
					foreach (NuDataExport::tables() as $n => $t) $tables[] = ['name' => $n, 'view' => $t['view'], 'system' => $t['system']];
					$selects = [];
					if (NuSqlSelect::available()) foreach (nu_db()->all('SELECT zzzzsys_select_id, sse_code, sse_description FROM zzzzsys_select ORDER BY sse_code') as $r) $selects[] = ['id' => (string)$r['zzzzsys_select_id'],
						'code' => (string)$r['sse_code'], 'name' => (string)$r['sse_description']];
					$json(['ok' => true, 'tables' => $tables, 'selects' => $selects]);
				}
				if ($action === 'columns') {
					$cols = [];
					foreach (NuDataExport::columns(nu_post('table')) as $n => $c) $cols[] = ['name' => $n, 'kind' => $c['kind'], 'pk' => $c['pk'], 'secret' => $c['secret'], 'type' => $c['type']];
					$json(['ok' => true, 'columns' => $cols]);
				}
				$d = NuDataExport::cleanDataset(json_decode((string)($_POST['dataset'] ?? ''), true));
				$values = json_decode((string)($_POST['values'] ?? ''), true);
				$values = is_array($values) ? array_map('strval', array_filter($values, 'is_scalar')) : [];
				if ($action === 'check') {
					$e = NuDataExport::checkDataset($d);
					$json(['ok' => $e === '', 'message' => $e, 'params' => $e === '' ? NuDataExport::params(['datasets' => [$d]]) : []]);
				}
				if ($action === 'count') $json(['ok' => true, 'count' => NuDataExport::count($d, $values)]);
				$e = NuDataExport::checkDataset($d);
				if ($e !== '') $json(['ok' => false, 'message' => $e]);
				$p = NuDataExport::preview($d, $values);
				$json(['ok' => true, 'cols' => $p['cols'], 'rows' => $p['rows'], 'params' => NuDataExport::params(['datasets' => [$d]])]);
			} catch (Throwable $x) {
				$msg = $x->getMessage();
				if (!NuAuth::user()['globeadmin'] && $x instanceof PDOException) $msg = t('dxp_err_query');
				$json(['ok' => false, 'message' => $msg]);
			}
		}
		return compact('x');
	}
}
