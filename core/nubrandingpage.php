<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nubranding.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuBrandingPage
{
	/** The POST actions of pages/nubranding.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$upload = $nuIn['upload'] ?? null; $errors = $nuIn['errors'] ?? null; $b0 = $nuIn['b0'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'hide_logo') {                          // right-click on the logo in the top bar: sign-in pages keep it
				$changed = NuBranding::hideTopLogo();
				nu_flash('success', $changed ? t('brand_hidden_msg', t('sh_title') . ' → ' . t('sh_look') . ' → ' . t('brand_title') . ' (' . t('brand_logo_show') . ')') : t('set_saved_none'));
				nu_redirect(NuAuth::validNext(nu_post('back')) ?? nu_url('home'));
			}
			if ($action === 'reset') {
				NuBranding::reset();
				nu_flash('success', t('brand_reset_done'));
				nu_redirect(nu_url('branding'));
			}
			if ($action === 'save') {
				$files = ['light' => $upload('logo_light', $errors), 'dark' => $upload('logo_dark', $errors)];
				if (!$errors) {
					[$errors, $warn] = NuBranding::save([
						'accent_on' => $b0['accent'] !== '', 'accent' => $b0['accent'],   // the accent colour is part of the themes – an older one is kept
						 'show_title' => nu_check('show_title') === '1', 'title' => nu_post('title'), 'title_phone' => nu_post('title_phone'),
						 	'show_title_phone' => nu_check('show_title_phone') === '1', 'pwa' => nu_check('pwa') === '1',
						'favicon' => nu_check('favicon') === '1', 'logo_show' => nu_post('logo_show'), 'env_label' => nu_post('env_label'), 'env_color' => nu_post('env_color'),
					], $files, ['light' => nu_check('remove_light') === '1', 'dark' => nu_check('remove_dark') === '1']);
					if (!$errors) {
						nu_flash('success', t('brand_saved'));
						foreach ($warn as $w) nu_flash('warning', $w);
						nu_redirect(nu_url('branding'));
					}
				}
			}
		}
		return compact('errors');
	}
}
