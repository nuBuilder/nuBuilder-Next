<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nupermissions.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuPermissionsPage
{
	/** The POST actions of pages/nupermissions.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$item = $nuIn['item'] ?? null;
		$src = $nuIn['src'] ?? null;
		$id = $nuIn['id'] ?? null;
		$T = $nuIn['T'] ?? null;
		$db = $nuIn['db'] ?? null;
		$cloneId = $nuIn['cloneId'] ?? null;
		$userEdit = $nuIn['userEdit'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save') {
				NuAcl::require(NuAcl::FORM_PERMISSION, $item ? 'save' : ($src ? 'clone' : 'create'));
				$in = ['pme_ident' => nu_post('pme_ident'), 'pme_group' => nu_post('pme_group'), 'pme_description' => (string)($_POST['pme_description'] ?? '')];
				// type of the item (own table – nuBuilder Forte's item stays a plain item)
				$ptype = isset(NuPermType::TYPES[nu_post('ptype')]) ? nu_post('ptype') : 'plain';
				$pset = NuPermType::clean($ptype,
					['tables' => is_array($_POST['ptype_tables'] ?? null) ? $_POST['ptype_tables'] : [], 'add' => nu_check('ptype_add') === '1', 'update' => nu_check('ptype_update') === '1']);
				$errors = NuPermType::errors($ptype, $pset);
				$newId = '';
				if (!$errors) [$newId, $errors] = NuPermission::save($in, $item ? $id : '');
				if (!$errors) {
					$oldType = $item ? NuPermType::of($id) : ['type' => 'plain', 'settings' => []];
					NuPermType::set((string)$newId, $ptype, $pset);
					if ($oldType['type'] !== $ptype || $oldType['settings'] != NuPermType::clean($ptype, $pset)) {
						NuLog::write('permission_type', $T, (string)$newId, ['type' => $ptype, 'before' => $oldType['type'], 'summary' => NuPermType::summary($ptype, NuPermType::clean($ptype, $pset))]);
					}
					$after = $db->one("SELECT * FROM $T WHERE zzzzsys_permission_item_id = ?", [$newId]) ?? [];
					NuLog::write($item ? 'permission_changed' : 'permission_created', $T, (string)$newId,
						$item ? ['fields' => NuLog::diff($item, $after)] : ['ident' => $after['pme_ident'] ?? ''] + ($src ? ['clone_of' => $cloneId] : []));
					if ($userEdit && isset($_POST['users_sent'])) {
						$users = is_array($_POST['users'] ?? null) ? $_POST['users'] : [];
						[$add, $rem] = NuPermission::setItemUsers((string)$newId, $users);
						if ($add || $rem) NuLog::write('permission_users', $T, (string)$newId, ['ident' => $after['pme_ident'] ?? '', 'added' => $add, 'removed' => $rem]);
					}
					nu_flash('success', t('saved'));
					nu_redirect(nu_url('permissions', ['id' => $newId]));
				}
				nu_flash('danger', t('err_check_form'));
			}
			if ($action === 'delete' && $item) {
				NuAcl::require(NuAcl::FORM_PERMISSION, 'delete');
				NuPermission::delete($id);
				NuPermType::delete($id);
				NuLog::write('permission_deleted', $T, $id, ['ident' => (string)$item['pme_ident']]);
				nu_flash('success', t('perm_deleted', (string)$item['pme_ident']));
				nu_redirect(nu_url('permissions'));
			}
		}
		return compact('item', 'src', 'errors', 'id', 'T', 'db', 'cloneId', 'userEdit');
	}
}
