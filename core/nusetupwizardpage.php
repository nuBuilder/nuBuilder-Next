<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nusetupwizard.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSetupwizardPage
{
	/** The POST actions of pages/nusetupwizard.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$state = $nuIn['state'] ?? null; $step = $nuIn['step'] ?? null; $i = $nuIn['i'] ?? null; $steps = $nuIn['steps'] ?? null; $errors = $nuIn['errors'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'skip') {
				unset($_SESSION[NuSess::SETUP_WIZARD]);
				$err = NuSetupWizard::markDone(true);
				nu_flash($err === '' ? 'success' : 'warning', $err === '' ? t('wiz_skipped') : t('wiz_skipped_db') . ' ' . $err);
				nu_redirect(nu_url('home'));
			}
			if ($action === 'apply') {
				$res = NuSetupWizard::apply($state);
				unset($_SESSION[NuSess::SETUP_WIZARD]);
				$state['pw1'] = $state['pw2'] = '';
				if ($res['manual'] !== '') {
					$_SESSION[NuSess::SETUP_WIZARD_MANUAL] = $res['manual'];
					nu_flash('warning', t('wiz_manual'));
				} else {
					foreach ($res['errors'] as $e) nu_flash('danger', $e);
					nu_flash('success', t('wiz_done'));
				}
				if ($res['sample'] !== null) $_SESSION[NuSess::SAMPLE_PW] = $res['sample'];
				$_SESSION[NuSess::TUT_OFFER] = 1;                         // the start page offers the tutorial
				if ($res['relogin']) {
					NuAuth::logout();
					nu_flash('success', t('wiz_relogin'));
					nu_redirect(nu_url('login'));
				}
				nu_redirect($res['manual'] !== '' ? nu_url('setupwizard', ['step' => 'done']) : ($res['sample'] !== null ? nu_url('sample') : nu_url('home')));
			}
			$state = NuSetupWizard::take($step, $state, $_POST);
			if ($action === 'lang') {                                      // language picked: the wizard switches to it at once (session language)
				$_SESSION[NuSess::SETUP_WIZARD] = $state;
				if (isset(nu_lang_choices()[(string)$state['lang']])) nu_set_lang((string)$state['lang']);
				nu_redirect(nu_url('setupwizard', ['step' => $step]));
			}
			if ($action === 'next') $errors = NuSetupWizard::validate($step, $state);
			$_SESSION[NuSess::SETUP_WIZARD] = $state;
			if (!$errors) {
				$to = $action === 'back' ? max(0, $i - 1) : min(count($steps) - 1, $i + 1);
				$go = str_starts_with($action, 'go:') ? array_search(substr($action, 3), $steps, true) : false;
				if ($go !== false && $go < $i) $to = (int)$go;               // a step already done, from the step bar (answers of this step kept)
				nu_redirect(nu_url('setupwizard', ['step' => $steps[$to]]));
			}
		}
		return compact('state', 'step', 'errors', 'i', 'steps');
	}
}
