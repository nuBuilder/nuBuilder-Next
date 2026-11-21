<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nusample.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuSamplePage
{
	/** The POST actions of pages/nusample.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$e = $nuIn['e'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			$set = nu_post('set');
			try {
				if ($set !== '' && in_array($set, NuSampleSet::keys(), true)) {     // further sample applications (core/nusampleset.php)
					if ($action === 'install' || $action === 'refresh') {
						$_SESSION[NuSess::SAMPLE_PW_SET] = [$set => NuSampleSet::install($set)];
						nu_flash('success', t($action === 'refresh' ? 'smp_refreshed' : 'smp_installed'));
					} elseif ($action === 'remove') {
						NuSampleSet::remove($set);
						nu_flash('success', t('smp_removed'));
					} elseif ($action === 'passwords') {
						$_SESSION[NuSess::SAMPLE_PW_SET] = [$set => NuSampleSet::newPasswords($set)];
						NuLog::write('sample_passwords', 'sample', $set);
						nu_flash('success', t('smp_passwords_done'));
					}
					nu_redirect(nu_url('sample') . '#smpSet_' . $set);
				}
				if ($action === 'install' || $action === 'refresh') {
					$_SESSION[NuSess::SAMPLE_PW] = NuSample::install();
					nu_flash('success', t($action === 'refresh' ? 'smp_refreshed' : 'smp_installed'));
				} elseif ($action === 'remove') {
					NuSample::remove();
					nu_flash('success', t('smp_removed'));
				} elseif ($action === 'passwords') {
					$_SESSION[NuSess::SAMPLE_PW] = NuSample::newPasswords();
					NuLog::write('sample_passwords', 'sample');
					nu_flash('success', t('smp_passwords_done'));
				}
			} catch (Throwable $e) {
				error_log('nuBuilder Next sample data: ' . $e->getMessage());
				nu_flash('danger', t('smp_failed') . ' ' . $e->getMessage());
			}
			nu_redirect(nu_url('sample', ['s' => 'sales']));
		}
		return compact('e');
	}
}
