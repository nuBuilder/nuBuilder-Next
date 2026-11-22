<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuadminroles.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuAdminrolesPage
{
	/** The POST actions of pages/nuadminroles.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$role = $nuIn['role'] ?? null; $errors = $nuIn['errors'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'delete' && $role) {
				$err = NuAdmin::delete($role['id']);
				if ($err === '') { nu_flash('success', t('adm_deleted', $role['name'])); nu_redirect(nu_url('adminroles')); }
				nu_flash('danger', $err);
				nu_redirect(nu_url('adminroles', ['id' => $role['id']]));
			}
			if ($action === 'save') {
				$rights = [];
				foreach (NuAdmin::rights() as $k => $_) {
					$v = (string)($_POST['r'][$k] ?? '');
					if ($v === 'view' || $v === 'change') $rights[$k] = $v;
				}
				[$newId, $errors] = NuAdmin::save($role['id'] ?? null, ['code' => nu_post('code'), 'name' => nu_post('name'), 'description' => nu_post('description'), 'rights' => $rights]);
				if (!$errors) { nu_flash('success', t('saved')); nu_redirect(nu_url('adminroles', ['id' => $newId])); }
				$role = ['id' => $role['id'] ?? '', 'code' => nu_post('code'), 'stored_name' => nu_post('name'), 'name' => nu_post('name'), 'description' => nu_post('description'), 'rights' => $rights];
				nu_flash('danger', t('err_check_form'));
			}
		}
		return compact('role', 'errors');
	}
}
