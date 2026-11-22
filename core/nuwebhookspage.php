<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuwebhooks.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuWebhooksPage
{
	/** The POST actions of pages/nuwebhooks.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$call = $nuIn['call'] ?? null; $callId = $nuIn['callId'] ?? null; $errors = $nuIn['errors'] ?? null; $hook = $nuIn['hook'] ?? null; $id = $nuIn['id'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'settings') {
				NuWebhook::setSettings(nu_check('allow_private') === '1');
				nu_flash('success', t('saved'));
				nu_redirect(nu_url('webhooks'));
			}
			if ($call && $action === 'resend') {
				$r = NuWebhook::resend($callId);
				nu_flash($r && $r['ok'] ? 'success' : 'warning', $r && $r['ok'] ? t('wh_test_ok', $r['code'], $r['ms']) : t('wh_test_fail', (string)($r['error'] ?? '')));
				nu_redirect(nu_url('webhooks', ['call' => $callId]));
			}
			if ($action === 'save') {
				$in = ['name' => nu_post('name'), 'active' => nu_check('active') === '1', 'forms' => (array)($_POST['forms'] ?? []), 'events' => (array)($_POST['events'] ?? []),
					'url' => nu_post('url'), 'fields' => nu_post('fields'), 'template' => nu_post('template'), 'new_secret' => nu_post('new_secret') === '1'];
				[$newId, $errors, $secret] = NuWebhook::save($in, $hook ? $id : '');
				if (!$errors) {
					if ($secret !== null) $_SESSION[NuSess::WEBHOOK_SHOWN] = ['id' => $newId, 'secret' => $secret];
					if (nu_post('do_test') === '1') {                       // "Send test": save, then one sample call now
						$r = NuWebhook::test((array)NuWebhook::get($newId));
						$_SESSION[NuSess::WEBHOOK_TEST] = ['id' => $newId] + $r;
					} else {
						nu_flash('success', t('saved'));
					}
					nu_redirect(nu_url('webhooks', ['id' => $newId]));
				}
				nu_flash('danger', t('err_check_form'));
			}
			if ($hook && $action === 'delete') {
				NuWebhook::delete($id);
				nu_flash('success', t('wh_deleted', $hook['name']));
				nu_redirect(nu_url('webhooks'));
			}
		}
		return compact('call', 'callId', 'errors', 'hook', 'id');
	}
}
