<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nusettings.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSettingsPage
{
	/** The POST actions of pages/nusettings.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$back = $nuIn['back'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'langs') {                         // languages of the installation (NuLanguages)
				NuAdmin::require('settings');
				$want = array_map('strval', is_array($_POST['langs'] ?? null) ? $_POST['langs'] : []);
				if (!array_intersect($want, array_keys(NU_LANGS))) {
					nu_flash('danger', t('lng_err_none'));
				} else {
					$counts = NuLanguages::userCounts();
					$varsBefore = array_map(static fn($b) => NuLanguages::variants($b), ['en' => 'en', 'de' => 'de']);
					NuLanguages::setVariants(is_array($_POST['variants'] ?? null) ? $_POST['variants'] : [], (string)(NuAuth::user()['login'] ?? ''));   // variants
					$varsAfter = array_map(static fn($b) => NuLanguages::variants($b), ['en' => 'en', 'de' => 'de']);
					$res = NuLanguages::set($want, (string)(NuAuth::user()['login'] ?? ''));
					NuLog::write('languages_changed', 'zzzzsys_nunext_setting', NuLanguages::KEY, ['languages' => NuLanguages::installed(), 'added' => $res['added'], 'removed' => $res['removed']]
						+ ($varsBefore !== $varsAfter ? ['variants' => $varsAfter] : []));
					$name = static fn(string $c): string => NU_LANGS[$c][0];
					nu_flash('success', t('lng_saved') . ($res['added'] ? ' ' . t('lng_added', implode(', ', array_map($name, $res['added']))) : ''));
					$affected = array_sum(array_map(static fn($c) => $counts[$c] ?? 0, $res['removed']));
					if ($res['removed']) nu_flash('info', t('lng_removed', implode(', ', array_map($name, $res['removed'])), $affected, $name(NuLanguages::fallback())));
				}
				$back('general', 'setLangs');
			}
			if ($action === 'page_width') {                    // default page width of the installation (NuPageWidth)
				if (!NuSettingsHub::ga()) NuAdmin::require('branding', 'change');
				$before = NuPageWidth::default();
				$mode = nu_post('pw_mode');
				$w = NuPageWidth::save($mode === 'custom' ? nu_post('pgw_px') : $mode, (string)(NuAuth::user()['login'] ?? ''));
				if ($w !== $before) NuLog::write('page_width_changed', 'zzzzsys_nunext_setting', NuPageWidth::KEY, ['from' => $before, 'to' => $w]);
				nu_flash('success', t('pgw_saved', NuPageWidth::label($w)));
				$back('look', 'shPageWidth');
			}
			if ($action === 'data_set') {                      // Data & logs (review E2): one form, one Save for audit trail, notifications and backups
				$parts = array_map('strval', is_array($_POST['parts'] ?? null) ? $_POST['parts'] : []);
				$msg = [];
				if (in_array('audit', $parts, true) && NuAdmin::can('audit', 'change')) {
					if (NuAudit::saveSettings(['on' => nu_check('aud_on') === '1', 'keep_days' => (int)nu_post('aud_keep_days'), 'exclude' => nu_post('aud_exclude')])) $msg[] = t('aud_set_saved');
				}
				if (in_array('notify', $parts, true) && (NuSettingsHub::ga() || NuAdmin::can('notifications', 'change'))) {
					$was = NuNotify::settings()['keep_days'];
					NuNotify::saveSettings((int)nu_post('ntf_keep_days'));
					if ((int)$was !== (int)NuNotify::settings()['keep_days']) $msg[] = t('ntf_settings_saved');
				}
				if (in_array('backups', $parts, true) && NuAdmin::can('update', 'change')) {
					$before = [NuUpdate::keepSql(), NuUpdate::cleanupRule()];
					NuUpdate::setKeepSql((int)nu_post('keep'));
					$mode = nu_post('mode') === 'age' ? 'age' : 'keep';
					NuUpdate::setCleanupRule($mode, max(1, min(9999, (int)nu_post($mode === 'age' ? 'days' : 'keep_n'))), nu_check('auto') === '1');
					if ($before !== [NuUpdate::keepSql(), NuUpdate::cleanupRule()]) {
						NuLog::write('backup_rules', 'zzzzsys_nunext_setting', '', ['keep_sql' => NuUpdate::keepSql(), 'cleanup' => NuUpdate::cleanupRule()]);
						$msg[] = t('saved');
					}
				}
				if (in_array('health', $parts, true) && NuSettingsHub::ga()) {   // health check thresholds
					if (NuHealth::saveLimits((int)nu_post('hc_backup_hours'), (int)nu_post('hc_cron_hours'), (int)nu_post('hc_disk_mb'))) $msg[] = t('saved');
				}
				if (in_array('cron', $parts, true) && (NuSettingsHub::ga() || NuAdmin::can('procedures', 'change'))) {
					$was = NuCronRun::settings();
					if (!NuCronRun::saveSettings((string)nu_post('cron_mail'))) { nu_flash('danger', t('cron_err_mail')); nu_redirect(nu_url('settings', ['s' => 'data']) . '#shCron'); }
					if ($was !== NuCronRun::settings()) $msg[] = t('saved');
				}
				nu_flash('success', $msg ? implode(' ', array_unique($msg)) : t('set_saved_none'));
				nu_redirect(nu_url('settings', ['s' => 'data']));
			}
			if ($action === 'health_key') {                    // health check key: shown once, only its hash is stored
				if (!NuSettingsHub::ga()) NuAcl::require('-');
				NuSecurity::requireRecentAuth(nu_url('settings', ['s' => 'data']) . '#shHealth');
				$remove = nu_post('key_op') === 'remove';
				$k = NuHealth::newKey($remove);
				if (!$remove) $_SESSION[NuSess::HEALTH_SHOWN] = $k;
				nu_flash('success', t($remove ? 'hc_key_removed' : 'hc_key_saved'));
				$back('data', 'shHealth');
			}
			if ($action === 'audit_set') {                     // audit trail (also on the audit page)
				NuAdmin::require('audit');
				$changed = NuAudit::saveSettings(['on' => nu_check('on') === '1', 'keep_days' => (int)nu_post('keep_days'), 'exclude' => nu_post('exclude')]);
				nu_flash('success', $changed ? t('aud_set_saved') : t('aud_set_nochange'));
				$back('data', 'shAudit');
			}
			if ($action === 'ntf_set') {                       // notification centre (also under Notifications → Manage)
				if (!NuSettingsHub::ga() && !NuAdmin::can('notifications', 'change')) NuAcl::require('-');
				NuNotify::saveSettings((int)nu_post('keep_days'));
				nu_flash('success', t('ntf_settings_saved'));
				$back('data', 'shNotify');
			}
			if ($action === 'bk_keep' || $action === 'bk_rule') {   // backups (also on the update page, tab Backups)
				NuAdmin::require('update');
				NuUpdate::setKeepSql((int)nu_post('keep'));
				$mode = nu_post('mode') === 'age' ? 'age' : 'keep';
				NuUpdate::setCleanupRule($mode, max(1, min(9999, (int)nu_post($mode === 'age' ? 'days' : 'keep_n'))), nu_check('auto') === '1');
				NuLog::write('backup_rules', 'zzzzsys_nunext_setting', '', ['keep_sql' => NuUpdate::keepSql(), 'cleanup' => NuUpdate::cleanupRule()]);
				nu_flash('success', t('saved'));
				$back('data', 'shBackups');
			}
			if ($action === 'gh_set') {                        // update from GitHub (also on the update page)
				NuAdmin::require('update');
				$changed = NuGitHub::saveSettings(nu_post('repo'), nu_check('pre') === '1', nu_post('every'));
				nu_flash('success', $changed ? t('gh_saved') : t('aud_set_nochange'));
				$back('updates', 'shGitHub');
			}
		}
		return [];
	}
}
