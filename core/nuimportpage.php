<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuimport.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuImportPage
{
	/** The POST actions of pages/nuimport.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		if (nu_is_post()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			$out = static fn(array $a) => exit(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
			try {
				nu_time_limit(600);
				$action = nu_post('action');
				$read = NuImport::readOptions($_POST);
				switch ($action) {
					case 'upload':
						$info = NuImport::upload($_FILES['file'] ?? []);
						$read = NuImport::readOptions(['delimiter' => $info['delimiter'], 'header' => $_POST['header'] ?? '1', 'quote' => $_POST['quote'] ?? '"']);
						$out(['ok' => true, 'file' => ['name' => $info['name'], 'size' => $info['size'], 'encoding' => $info['encoding']],
							'delimiter' => $info['delimiter'] === "\t" ? 'tab' : $info['delimiter'], 'sheets' => $info['sheets'] ?? [], 'sheet' => $info['sheet'] ?? 0] + NuImport::preview($read));
					case 'sheet':                                                 // another sheet of the uploaded spreadsheet
						$info = NuImport::chooseSheet((int)nu_post('sheet'));
						$read = NuImport::readOptions(['delimiter' => ',', 'header' => $_POST['header'] ?? '1', 'quote' => '"']);
						$out(['ok' => true, 'sheets' => $info['sheets'], 'sheet' => $info['sheet'], 'delimiter' => ','] + NuImport::preview($read));
					case 'preview':
						$out(['ok' => true] + NuImport::preview($read));
					case 'columns':
						$out(['ok' => true, 'columns' => array_values(NuImport::columns(nu_post('table')))]);
					case 'sample':
						$table = nu_post('table');
						$cols = NuImport::columns($table);
						$width = count(NuImport::preview($read)['headers']);
						$map = NuImport::mapping(json_decode((string)($_POST['mapping'] ?? '{}'), true) ?: [], $cols, $width);
						$out(['ok' => true, 'sample' => NuImport::sample($read, $cols, $map, NuImport::options($_POST))]);
					case 'check':
					case 'import':
						$table = nu_post('table');
						$opt = NuImport::options($_POST);
						$res = NuImport::run($table, $read, json_decode((string)($_POST['mapping'] ?? '{}'), true) ?: [], $opt, $action === 'check');
						if ($action === 'import') {
							$file = NuImport::current();
							$via = $table !== NuImport::USERS && !NuImport::allTables() ? (NuImport::itemRules()[$table]['items'] ?? []) : [];
							NuLog::write($res['rolled_back'] ? 'csv_import_failed' : 'csv_imported', $table, '', ($via ? ['permission_items' => $via] : []) + [
								'file' => $file['name'] ?? '', 'mode' => $opt['mode'], 'inserted' => $res['inserted'], 'updated' => $res['updated'],
								'failed' => $res['failed'], 'rows' => $res['rows'], 'rolled_back' => $res['rolled_back']] + ($table === NuImport::USERS ? ['skipped' => $res['skipped'], 'welcome' => $res['welcome']] : []));
							if (!$res['rolled_back']) NuImport::discard();       // done: the temp file is deleted
						}
						$out(['ok' => true] + $res);
					case 'discard':
						NuImport::discard();
						$out(['ok' => true]);
				}
				$out(['ok' => false, 'error' => t('err_not_found')]);
			} catch (Throwable $e) {
				if (!$e instanceof RuntimeException) error_log('nuBuilder Next CSV import: ' . $e->getMessage());
				$out(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : t('imp_err_general') . (nu_debug() ? ' ' . $e->getMessage() : '')]);
			}
		}
		return [];
	}
}
