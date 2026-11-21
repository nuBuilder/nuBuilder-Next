<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nufilemanager.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuFilemanagerPage
{
	/** The POST actions of pages/nufilemanager.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$ga = $nuIn['ga'] ?? null;
		if (nu_is_post() && nu_post('action') === 'setup') {
			if (!$ga) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
			NuSecurity::requireRecentAuth(nu_url('filemanager'));
			try {
				NuFileManager::setup();
				nu_flash('success', t('fm_setup_done'));
			} catch (Throwable $e) {
				nu_flash('danger', t('fm_setup_failed') . ' ' . $e->getMessage());
			}
			nu_redirect(nu_url('filemanager'));
		}
		return compact('ga');
	}
}
