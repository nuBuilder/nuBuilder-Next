<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nusettings_forte.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSettingsFortePage
{
	/** The POST actions of pages/nusettings_forte.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		if (nu_is_post() && nu_post('action') === 'create') {
			try {
				$n = NuSettings::create();
				NuLog::write('settings_created', NuSettings::TABLE, '', ['rows' => $n]);
				nu_flash('success', t('set_created', $n));
			} catch (Throwable $e) {
				error_log('nuBuilder Next settings: ' . $e->getMessage());
				nu_flash('danger', t('set_create_failed') . ' ' . $e->getMessage());
			}
			nu_redirect(nu_url('settings', ['s' => 'forte']));
		}
		return [];
	}
}
