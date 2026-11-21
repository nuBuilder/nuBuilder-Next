<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuipaccess.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuIpaccessPage
{
	/** The POST actions of pages/nuipaccess.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$errors = $nuIn['errors'] ?? null; $levelIds = $nuIn['levelIds'] ?? null; $s = $nuIn['s'] ?? null; $u = $nuIn['u'] ?? null; $id = $nuIn['id'] ?? null; $form = $nuIn['form'] ?? null;
		if (nu_is_post() && nu_post('action') === 'save') {
			NuSecurity::requireRecentAuth(nu_url('ipaccess'));
			$bad = static function (string $field, array $wrong) use (&$errors): void {
				if ($wrong) $errors[$field] = t('ipa_err_rule', implode(', ', array_slice($wrong, 0, 5)));
			};
			[$def, $wrong] = NuIpAccess::parse((string)nu_post('default'));
			$bad('default', $wrong);
			$gaMode = in_array(nu_post('ga_mode'), NuIpAccess::GA_MODES, true) ? (string)nu_post('ga_mode') : 'default';
			[$gaRanges, $wrong] = NuIpAccess::parse((string)nu_post('ga_ranges'));
			$bad('ga_ranges', $wrong);
			if ($gaMode === 'ranges' && !$gaRanges && !isset($errors['ga_ranges'])) $errors['ga_ranges'] = t('ipa_err_empty');
			$new = ['on' => nu_check('on') === '1', 'default' => $def, 'levels' => [], 'ga' => ['mode' => $gaMode, 'ranges' => $gaRanges]];
			$modes = is_array($_POST['lv_mode'] ?? null) ? $_POST['lv_mode'] : [];
			$texts = is_array($_POST['lv_ranges'] ?? null) ? $_POST['lv_ranges'] : [];
			foreach ($levelIds as $id) {
				$m = (string)($modes[$id] ?? 'default');
				if ($m === 'any') $new['levels'][$id] = ['mode' => 'any', 'ranges' => []];
				elseif ($m === 'ranges') {
					[$r, $wrong] = NuIpAccess::parse((string)($texts[$id] ?? ''));
					$bad('lv_' . $id, $wrong);
					if (!$r && !isset($errors['lv_' . $id])) $errors['lv_' . $id] = t('ipa_err_empty');
					$new['levels'][$id] = ['mode' => 'ranges', 'ranges' => $r];
				}
			}
			// the globeadmin saving must not lock himself out
			if (!$errors && $new['on'] && !NuIpAccess::gaAllows(NuIpAccess::ip(), NuIpAccess::clean($new))) $errors['_self'] = t('ipa_err_self', NuIpAccess::ip());
			if ($errors) {
				$form = $_POST;
				nu_flash('danger', t('err_check_form'));
			} else {
				$before = $s;
				$saved = NuIpAccess::save($new, (string)$u['login']);
				if ($saved !== $before) NuLog::write('ip_access_changed', 'zzzzsys_nunext_setting', NuIpAccess::KEY, ['on' => $saved['on'] ? 1 : 0,
					'default' => implode(' ', $saved['default']), 'exceptions' => count($saved['levels']), 'ga' => $saved['ga']['mode']]);
				nu_flash('success', $saved !== $before ? t('ipa_saved') : t('set_saved_none'));
				nu_redirect(nu_url('ipaccess'));
			}
		}
		return compact('errors', 'levelIds', 'id', 'form', 's');
	}
}
