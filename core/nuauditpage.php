<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuaudit.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuAuditPage
{
	/** The POST actions of pages/nuaudit.php (part 1) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post1(array $nuIn): array
	{
		$canSet = $nuIn['canSet'] ?? null;
		if (nu_is_post() && nu_post('action') === 'audit_settings') {
			if (!$canSet) NuAcl::require('-');
			NuSecurity::requireRecentAuth(nu_url('audit', ['settings' => '1']));
			$changed = NuAudit::saveSettings(['on' => nu_check('on') === '1', 'keep_days' => (int)nu_post('keep_days'), 'exclude' => nu_post('exclude')]);
			nu_flash('success', $changed ? t('aud_set_saved') : t('aud_set_nochange'));
			nu_redirect(nu_url('audit'));
		}
		return compact('canSet');
	}

	/** The POST actions of pages/nuaudit.php (part 2) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post2(array $nuIn): array
	{
		$canDelete = $nuIn['canDelete'] ?? null;
		if (nu_is_post() && in_array(nu_post('action'), ['audit_count', 'audit_csv', 'audit_delete'], true)) {
			if (!$canDelete) NuAcl::require('-');
			$sel = NuAudit::selection($_POST);
			$back = nu_url('audit', array_diff_key($_GET, ['p' => 1]));
			switch (nu_post('action')) {
				case 'audit_count':
					header('Content-Type: application/json; charset=utf-8');
					header('Cache-Control: no-store');
					$c = NuAudit::countSelection($sel);
					exit(json_encode(['ok' => true, 'rows' => $c['rows'], 'all' => $sel['all'],
						'text' => $c['rows'] ? t('aud_del_count', number_format($c['rows'], 0, '', ' '), number_format($c['changes'], 0, '', ' ')) : t('aud_del_none')], JSON_UNESCAPED_UNICODE));
				case 'audit_csv':
					NuAudit::csv($sel);
				case 'audit_delete':
					NuSecurity::requireRecentAuth($back);
					if ($sel['all'] && nu_post('confirm_all') !== 'DELETE') { nu_flash('danger', t('aud_del_err_confirm')); nu_redirect($back); }
					$n = NuAudit::deleteSelection($sel, (string)(NuAuth::user()['login'] ?? ''));
					nu_flash('success', t('aud_del_done', number_format($n, 0, '', ' ')));
					nu_redirect($sel['filtered'] ? nu_url('audit') : $back);
			}
		}
		return compact('canDelete');
	}
}
