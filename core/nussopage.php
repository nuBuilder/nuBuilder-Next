<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nusso.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSsoPage
{
	/** The POST actions of pages/nusso.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$levelIds = $nuIn['levelIds'] ?? null;
		$s = $nuIn['s'] ?? null;
		$u = $nuIn['u'] ?? null;
		$g = $nuIn['g'] ?? null;
		$a = $nuIn['a'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$form = $nuIn['form'] ?? null;
		if (nu_is_post() && nu_post('action') === 'save') {
			NuSecurity::requireRecentAuth(nu_url('sso'));
			$map = [];
			$gs = is_array($_POST['map_group'] ?? null) ? $_POST['map_group'] : [];
			$as = is_array($_POST['map_access'] ?? null) ? $_POST['map_access'] : [];
			foreach ($gs as $i => $g) {
				$a = (string)($as[$i] ?? '');
				if (trim((string)$g) !== '' && in_array($a, $levelIds, true)) $map[] = ['group' => (string)$g, 'access' => $a];
			}
			$in = ['on' => nu_check('on') === '1', 'kind' => (string)nu_post('kind'), 'label' => (string)nu_post('label'), 'issuer' => (string)nu_post('issuer'),
				'client_id' => (string)nu_post('client_id'), 'client_secret' => (string)nu_post('client_secret'), 'scopes' => (string)nu_post('scopes'),
				'domains' => (string)nu_post('domains'), 'create' => nu_check('create') === '1',
				'default_access' => in_array((string)nu_post('default_access'), $levelIds, true) ? (string)nu_post('default_access') : '',
				'group_claim' => (string)nu_post('group_claim'), 'group_map' => $map, 'sync' => nu_check('sync') === '1',
				'sso_only' => array_values(array_intersect(array_map('strval', is_array($_POST['sso_only'] ?? null) ? $_POST['sso_only'] : []), $levelIds))];
			$clean = NuSso::clean($in);
			$errors = NuSso::errors($clean, $in['client_secret'] !== '' || $s['client_secret'] !== '');
			if (trim($in['issuer']) !== '' && !NuSso::validIssuer($clean['issuer'])) $errors['issuer'] = t('sso_err_issuer');
			if ($errors) {
				$form = $_POST;
				nu_flash('danger', t('err_check_form'));
			} else {
				$before = $s;
				$saved = NuSso::save($in, (string)$u['login']);
				$changed = $saved !== $before;
				if ($changed) NuLog::write('sso_settings_changed', 'zzzzsys_nunext_setting', NuSso::KEY, ['on' => $saved['on'] ? 1 : 0, 'kind' => $saved['kind'], 'issuer' => $saved['issuer'],
					'create' => $saved['create'] ? 1 : 0, 'map' => count($saved['group_map']), 'sso_only' => count($saved['sso_only']), 'secret' => $in['client_secret'] !== '' ? 'changed' : 'kept']);
				nu_flash('success', $changed ? t('sso_saved') : t('set_saved_none'));
				nu_redirect(nu_url('sso'));
			}
		}
		return compact('g', 'a', 'errors', 's', 'form');
	}
}
