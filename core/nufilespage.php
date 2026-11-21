<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nufiles.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuFilesPage
{
	/** The POST actions of pages/nufiles.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$sysLocked = $nuIn['sysLocked'] ?? null;
		$file = $nuIn['file'] ?? null;
		$src = $nuIn['src'] ?? null;
		$upload = $nuIn['upload'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$id = $nuIn['id'] ?? null;
		$T = $nuIn['T'] ?? null;
		$cloneId = $nuIn['cloneId'] ?? null;
		$db = $nuIn['db'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($sysLocked && in_array($action, ['save', 'delete'], true)) NuAcl::require('__globeadmin_only__');
			if ($action === 'save') {
				NuAcl::require(NuAcl::FORM_FILE, $file ? 'save' : ($src ? 'clone' : 'create'));
				$in = ['sfi_code' => nu_post('sfi_code'), 'sfi_description' => nu_post('sfi_description'), 'sfi_group' => nu_post('sfi_group')];
				$up = $upload($errors);
				$newId = '';
				if (!$errors) [$newId, $errors] = NuFile::save($in, $up, $file ? $id : '', ($file ?? $src)['sfi_json'] ?? null);   // a clone keeps the file of its source
				if (!$errors) {
					NuLog::write($file ? 'file_changed' : 'file_created', $T, (string)$newId,
						['code' => $in['sfi_code']] + ($src ? ['clone_of' => $cloneId] : []) + ($up ? ['name' => NuFile::cleanName($up['name']), 'size' => strlen($up['content'])] : []));
					nu_flash('success', t('saved'));
					nu_redirect(nu_url('files', ['id' => $newId]));
				}
			}
			if ($action === 'delete' && $file) {
				NuAcl::require(NuAcl::FORM_FILE, 'delete');
				$used = NuFile::usage($id);
				if ($used) {
					$errors['_'] = t('file_err_used', implode(', ', array_map(static fn($u) => trim(($u['sfo_code'] ?? '') . ' · ' . $u['sob_all_id'], ' ·'), $used)));
				} else {
					$db->delete($T, 'zzzzsys_file_id', $id);
					NuLog::write('file_deleted', $T, $id, ['code' => (string)$file['sfi_code']]);
					nu_flash('success', t('file_deleted'));
					nu_redirect(nu_url('files'));
				}
			}
		}
		return compact('sysLocked', 'file', 'src', 'errors', 'id', 'T', 'cloneId', 'db');
	}
}
