<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuaccount.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuAccountPage
{
	/** The POST actions of pages/nuaccount.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$readOnly = $nuIn['readOnly'] ?? null; $errors = $nuIn['errors'] ?? null; $id = $nuIn['id'] ?? null; $own = $nuIn['own'] ?? null; $vals = $nuIn['vals'] ?? null;
		if (nu_is_post() && nu_post('action') === 'save' && !$readOnly) {
			$post = is_array($_POST['uf'] ?? null) ? $_POST['uf'] : [];
			[$new, $errors] = NuUserFields::validate($post, $id, 'self');
			if (!$errors) {
				$diff = NuUserFields::save($id, $new);
				if ($diff) { NuLog::write('user_updated', 'zzzzsys_user', $id, ['fields' => $diff, 'via' => 'account']); NuUserJson::stamp($id, false, 'account'); }
				nu_flash('success', t('saved'));
				nu_redirect(nu_url('account'));
			}
			$vals = array_merge($vals, array_map(static fn($x) => is_scalar($x) ? (string)$x : '', array_intersect_key($post, $own)));
			nu_flash('danger', t('err_check_form'));
		}
		return compact('readOnly', 'errors', 'vals', 'own');
	}
}
