<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuapiclients.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuApiclientsPage
{
	/** The POST actions of pages/nuapiclients.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$errors = $nuIn['errors'] ?? null; $client = $nuIn['client'] ?? null; $id = $nuIn['id'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save') {
				$in = [
					'name' => nu_post('name'), 'scopes' => is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [], 'user_id' => nu_post('user_id'),
					'ip_allow' => nu_post('ip_allow'), 'rate_limit' => nu_post('rate_limit'), 'expires_on' => nu_post('expires_on'), 'active' => nu_check('active') === '1',
				];
				$suMode = nu_post('su_mode');                                       // New service user / Existing user
				if ($suMode === 'new') $in['user_id'] = '__new';
				elseif ($suMode === 'existing' && $in['user_id'] === '__new') $in['user_id'] = '';
				if ($in['user_id'] === '__new') {                                   // create the service user first
					[$uid, $ue] = NuApiClient::createServiceUser(nu_post('su_login'), nu_post('su_name') ?: $in['name'], nu_post('su_access'));
					$errors += $ue;
					$in['user_id'] = (string)$uid;
				}
				if (!$errors) {
					[$newId, $e, $cred] = NuApiClient::save($in, $client ? $id : '');
					$errors += $e;
					if (!$errors) {
						if ($cred) $_SESSION[NuSess::API_SECRET] = ['id' => $newId] + $cred;
						nu_flash('success', t('saved'));
						nu_redirect(nu_url('apiclients', ['id' => $newId]));
					}
				}
			}
			if ($client && $action === 'rotate') {
				$secret = NuApiClient::rotate($id, nu_post('grace') === '0' ? 0 : 24);
				$_SESSION[NuSess::API_SECRET] = ['id' => $id, 'client_id' => $client['client_id'], 'client_secret' => $secret];
				nu_redirect(nu_url('apiclients', ['id' => $id]));
			}
			if ($client && $action === 'revoke') {
				nu_flash('success', t('api_revoked', NuApiClient::revokeTokens($id)));
				nu_redirect(nu_url('apiclients', ['id' => $id]));
			}
			if ($client && $action === 'delete') {
				NuApiClient::delete($id);
				nu_flash('success', t('api_deleted', (string)$client['name']));
				nu_redirect(nu_url('apiclients'));
			}
		}
		return compact('errors', 'client', 'id');
	}
}
