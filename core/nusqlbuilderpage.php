<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nusqlbuilder.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSqlbuilderPage
{
	/** The POST actions of pages/nusqlbuilder.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$rights = $nuIn['rights'] ?? null;
		$e = $nuIn['e'] ?? null;
		$q = $nuIn['q'] ?? null;
		$src = $nuIn['src'] ?? null;
		$locked = $nuIn['locked'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$id = $nuIn['id'] ?? null;
		$T = $nuIn['T'] ?? null;
		$db = $nuIn['db'] ?? null;
		$cloneId = $nuIn['cloneId'] ?? null;
		if (nu_is_post() && nu_post('action') === 'preview') {
			header('Content-Type: application/json; charset=utf-8');
			if (!$rights['save'] && !$rights['create']) {
				echo json_encode(['ok' => false, 'message' => t('sqb_err_preview_right')], JSON_UNESCAPED_UNICODE);
				exit;
			}
			try {
				echo json_encode(['ok' => true] + NuSqlSelect::preview((string)($_POST['sql'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			} catch (Throwable $e) {
				// details (table and column names in SQL errors) only for the globeadmin or with $nuConfigDebug
				$u = NuAuth::user();
				$own = $e instanceof RuntimeException && !($e instanceof PDOException);
				$msg = $own || ($u['globeadmin'] && empty($u['demo'])) || nu_debug() ? $e->getMessage() : t('sqb_err_failed_generic');
				echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			}
			exit;
		}

		// ------------------------------------------------------------------ save / clone / delete
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save') {
				NuAcl::require(NuAcl::FORM_SELECT, $q ? 'save' : ($src ? 'clone' : 'create'));
				if ($locked) NuAcl::require('__globeadmin_only__');
				$in = ['sse_code' => $_POST['sse_code'] ?? '', 'sse_description' => $_POST['sse_description'] ?? '', 'sse_json' => $_POST['sse_json'] ?? '',
					'sse_sql' => $_POST['sse_sql'] ?? '', 'sse_edit' => nu_check('sse_edit'), 'clauses' => json_decode((string)($_POST['clauses'] ?? '[]'), true) ?: []];
				[$newId, $errors] = NuSqlSelect::save($in, $q ? $id : '');
				if (!$errors) {
					NuLog::write($q ? 'sql_changed' : 'sql_created', $T, $newId, ['code' => (string)$in['sse_code'], 'description' => (string)$in['sse_description']]
						+ ($src ? ['clone_of' => (string)$db->value("SELECT sse_code FROM $T WHERE zzzzsys_select_id = ?", [$cloneId])] : []));
					nu_flash('success', t('saved'));
					nu_redirect(nu_url('sqlbuilder', ['id' => $newId]));
				}
			}
			if ($action === 'delete' && $q) {
				NuAcl::require(NuAcl::FORM_SELECT, 'delete');
				$used = NuSqlSelect::usage($id);
				if ($used) {
					$errors['_'] = t('sqb_err_used', implode(', ', array_column($used, 'sre_code')));
				} else {
					NuSqlSelect::delete($id);
					NuLog::write('sql_deleted', $T, $id, ['code' => (string)$q['sse_code'], 'description' => (string)$q['sse_description']]);
					nu_flash('success', t('sqb_deleted'));
					nu_redirect(nu_url('sqlbuilder'));
				}
			}
		}
		return compact('rights', 'q', 'src', 'locked', 'errors', 'id', 'cloneId');
	}
}
