<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuaiproviders.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuAiprovidersPage
{
	/** The POST actions of pages/nuaiproviders.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$prov = $nuIn['prov'] ?? null; $id = $nuIn['id'] ?? null; $errors = $nuIn['errors'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'settings') {
				NuAiProvider::setSettings(nu_check('allow_private') === '1');
				nu_flash('success', t('saved'));
				nu_redirect(nu_url('aiproviders'));
			}
			if ($action === 'models') {                                      // JSON: the models of the service, with the posted (unsaved) values
				header('Content-Type: application/json; charset=utf-8');
				$kind = nu_post('kind');
				$stored = $prov ? NuAiProvider::apiKey($prov) : '';             // null = encrypted with the password and still locked
				$d = ['id' => $id, 'kind' => isset(NuAiProvider::KINDS[$kind]) ? $kind : 'openai', 'base_url' => trim(nu_post('base_url')),
					'api_key' => 'plain:' . (trim(nu_post('api_key')) !== '' ? trim(nu_post('api_key')) : (string)$stored)];
				$e = $d['base_url'] !== '' ? NuAiProvider::checkUrl($d['base_url'], false) : '';
				if ($e === '' && $stored === null && trim(nu_post('api_key')) === '') $e = t('aip_err_locked');
				[$models, $err] = $e !== '' ? [[], $e] : NuAiProvider::models($d);
				echo json_encode(['ok' => $err === '', 'models' => $models, 'error' => $err], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
				exit;
			}
			if ($action === 'save') {
				$in = ['name' => nu_post('name'), 'kind' => nu_post('kind'), 'base_url' => nu_post('base_url'), 'api_key' => nu_post('api_key'), 'model' => nu_post('model'),
					'temperature' => nu_post('temperature'), 'max_tokens' => nu_post('max_tokens'), 'timeout' => nu_post('timeout'), 'system' => nu_post('system'), 'params' => nu_post('params'),
					'active' => nu_check('active') === '1', 'access' => nu_post('access'), 'levels' => (array)($_POST['levels'] ?? []), 'password' => (string)($_POST['password'] ?? ''),
					'month_limit' => nu_post('month_limit')];
				[$newId, $errors] = NuAiProvider::save($in, $prov ? $id : '');
				if (!$errors) {
					if (nu_post('do_test') === '1') {
						$r = NuAiProvider::test((array)NuAiProvider::get($newId));
						$_SESSION[NuSess::AI_TEST] = ['id' => $newId] + $r;
					} else {
						nu_flash('success', t('saved'));
					}
					nu_redirect(nu_url('aiproviders', ['id' => $newId]));
				}
				nu_flash('danger', t('err_check_form'));
			}
			if ($prov && $action === 'unlock') {                              // the password of a protected configuration, for this session
				$e = NuAiProvider::unlock($id, (string)($_POST['unlock_password'] ?? ''), true);
				nu_flash($e === '' ? 'success' : 'danger', $e === '' ? t('aip_unlocked') : $e);
				nu_redirect(nu_url('aiproviders', ['id' => $id]));
			}
			if ($prov && $action === 'delete') {
				NuAiProvider::delete($id);
				nu_flash('success', t('aip_deleted', $prov['name']));
				nu_redirect(nu_url('aiproviders'));
			}
		}
		return compact('prov', 'id', 'errors');
	}
}
