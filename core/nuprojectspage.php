<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuprojects.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuProjectsPage
{
	/** The POST actions of pages/nuprojects.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$back = $nuIn['back'] ?? null;
		$ga = $nuIn['ga'] ?? null;
		$pkg = $nuIn['pkg'] ?? null;
		$tmpOf = $nuIn['tmpOf'] ?? null;
		$e = $nuIn['e'] ?? null;
		$canChange = $nuIn['canChange'] ?? null;
		$list = $nuIn['list'] ?? null;
		$k = $nuIn['k'] ?? null;
		$err = $nuIn['err'] ?? null;
		$n = $nuIn['n'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			$pid = nu_post('project');
			if ($action === 'candidates' || $action === 'suggest') {                  // JSON, read only
				header('Content-Type: application/json; charset=utf-8');
				if ($action === 'candidates') {
					$kind = nu_post('kind');
					$have = array_flip(NuProject::refs($pid, $kind));
					$list = array_map(static fn($c) => ['ref' => $c[0], 'label' => $c[1], 'sub' => $c[2], 'member' => isset($have[$c[0]])], NuProject::candidates($kind, mb_substr(nu_post('q'), 0, 100)));
					echo json_encode(['ok' => true, 'items' => $list], JSON_UNESCAPED_UNICODE);
				} else {
					$kinds = [];
					foreach (array_keys(NuProject::KINDS) as $k) $kinds[$k] = t('prj_kind_' . $k);
					echo json_encode(['ok' => true, 'kinds' => $kinds, 'items' => NuProject::load($pid) ? NuProject::suggest($pid) : []], JSON_UNESCAPED_UNICODE);
				}
				exit;
			}
			if ($action === 'work') {                                                  // Working on: session only
				$on = nu_post('on') === '1' && NuProject::load($pid);
				NuProject::setWorking($on ? $pid : '');
				nu_flash('success', $on ? t('prj_working_on', (string)(NuProject::load($pid)['name'] ?? '')) : t('prj_working_off'));
				$to = nu_post('to');
				nu_redirect($to !== '' && str_starts_with($to, 'index.php') ? $to : ($pid !== '' ? $back($pid) : nu_url('projects')));
			}
			if ($action === 'import_upload' || $action === 'import_run' || $action === 'import_cancel') {
				if (!$ga) { nu_flash('danger', t('uf_edit_globeadmin')); nu_redirect(nu_url('projects')); }
				if ($action === 'import_upload') {
					$f = $_FILES['file'] ?? null;
					if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) { nu_flash('danger', t('upd_err_upload')); nu_redirect(nu_url('projects')); }
					[$pkg, $err] = NuProjectPackage::read((string)file_get_contents((string)$f['tmp_name']));
					if (!$pkg) { nu_flash('danger', $err); nu_redirect(nu_url('projects')); }
					$tok = bin2hex(random_bytes(12));
					file_put_contents($tmpOf($tok), NuProjectPackage::json($pkg));
					@chmod($tmpOf($tok), 0600);
					nu_redirect(nu_url('projects', ['import' => $tok]));
				}
				$tok = nu_post('token');
				$file = $tmpOf($tok);
				if ($action === 'import_cancel') { if ($file !== '') @unlink($file); nu_redirect(nu_url('projects')); }
				NuSecurity::requireRecentAuth(nu_url('projects', ['import' => $tok]));
				[$pkg, $err] = $file !== '' && is_file($file) ? NuProjectPackage::read((string)file_get_contents($file)) : [null, t('prj_err_package')];
				if (!$pkg) { nu_flash('danger', $err); nu_redirect(nu_url('projects')); }
				$acts = is_array($_POST['act'] ?? null) ? array_map('strval', $_POST['act']) : [];
				try {
					$r = NuProjectPackage::import($pkg, $acts);
				} catch (Throwable $e) {
					nu_flash('danger', $e->getMessage());
					nu_redirect(nu_url('projects', ['import' => $tok]));
				}
				@unlink($file);
				$parts = [];
				foreach ($r['done'] as $k => $n) $parts[] = $n . ' × ' . t('prj_kind_' . $k);
				nu_flash($r['errors'] ? 'warning' : 'success', t('prj_imp_done', $parts ? implode(', ', $parts) : '0', $r['skipped']));
				$gone = [];
				foreach ($r['deleted'] as $k => $n) $gone[] = $n . ' × ' . t('prj_kind_' . $k);
				if ($gone) nu_flash('info', t('prj_imp_deleted', implode(', ', $gone)));
				foreach (array_slice($r['errors'], 0, 10) as $e) nu_flash('danger', $e);
				if ($r['restore'] !== '') {
					nu_flash('info', t('prj_imp_restore_done', $r['restore']), [nu_url('projects', ['restorefile' => $r['restore']]), t('prj_restore_download')]);
				}
				nu_redirect($r['project'] !== '' ? $back($r['project']) : nu_url('projects'));
			}
			if (!$canChange) { nu_flash('danger', t('prj_err_rights')); nu_redirect(nu_url('projects')); }
			if ($action === 'save') {
				[$newId, $errors] = NuProject::save($pid, $_POST);
				if ($errors) {
					$_SESSION[NuSess::PROJECT_FORM] = ['in' => $_POST, 'errors' => $errors];
					nu_redirect($pid !== '' ? $back($pid, 'settings') : nu_url('projects', ['id' => 'new']));
				}
				nu_flash('success', t('saved'));
				nu_redirect($back($newId, $pid !== '' ? 'settings' : ''));
			}
			if (!NuProject::load($pid)) { nu_flash('warning', t('record_not_found')); nu_redirect(nu_url('projects')); }
			switch ($action) {
				case 'delete':
					NuProject::delete($pid);
					nu_flash('success', t('prj_deleted'));
					nu_redirect(nu_url('projects'));
				case 'add_items':
					$list = [];
					foreach ((array)($_POST['items'] ?? []) as $x) if (is_string($x) && str_contains($x, '|')) $list[] = explode('|', $x, 2);
					$n = NuProject::addItems($pid, $list);
					nu_flash($n ? 'success' : 'info', t('prj_items_added', $n));
					nu_redirect($back($pid, 'items'));
				case 'remove_item':
					NuProject::removeItem($pid, nu_post('item'));
					nu_flash('success', t('prj_item_removed'));
					nu_redirect($back($pid, 'items'));
				case 'note_save':
					[, $e] = NuProject::saveNote($pid, nu_post('note'), $_POST);
					nu_flash($e ? 'danger' : 'success', $e ? implode(' ', $e) : t('saved'));
					nu_redirect($back($pid, 'notes'));
				case 'note_delete':
					NuProject::deleteNote($pid, nu_post('note'));
					nu_redirect($back($pid, 'notes'));
				case 'task_save':
					[, $e] = NuProject::saveTask($pid, nu_post('task'), $_POST);
					nu_flash($e ? 'danger' : 'success', $e ? implode(' ', $e) : t('saved'));
					nu_redirect($back($pid, 'tasks'));
				case 'task_done':
					NuProject::setTaskStatus($pid, nu_post('task'), nu_check('done') === '1');
					nu_redirect(nu_post('from') === 'overview' ? $back($pid) : $back($pid, 'tasks'));
				case 'task_delete':
					NuProject::deleteTask($pid, nu_post('task'));
					nu_redirect($back($pid, 'tasks'));
			}
			nu_redirect($back($pid));
		}
		return compact('list', 'k', 'back', 'ga', 'pkg', 'err', 'tmpOf', 'n', 'canChange');
	}
}
