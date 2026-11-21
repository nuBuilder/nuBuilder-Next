<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nujquery.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuJqueryPage
{
	/** The POST actions of pages/nujquery.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		if (nu_is_post()) {
			NuAdmin::require('globalcode', 'change');
			$action = nu_post('action');
			if ($action === 'save') {
				$changed = NuJquery::save(['jq' => nu_post('jq'), 'migrate' => nu_post('migrate'), 'ga_jq' => nu_post('ga_jq'), 'ga_warn' => nu_check('ga_warn') === '1']);
				nu_flash('success', $changed ? t('saved') : t('aud_set_nochange'));
			}
			if ($action === 'clear') {
				NuJquery::clearWarnings();
				nu_flash('success', t('jq_cleared'));
			}
			nu_redirect(nu_url('jquery'));
		}
		return [];
	}
}
