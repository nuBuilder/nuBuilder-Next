<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nudbsetup.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuDbsetupPage
{
	/** The POST actions of pages/nudbsetup.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$auth = $nuIn['auth'] ?? null; $errors = $nuIn['errors'] ?? null; $done = $nuIn['done'] ?? null; $st = $nuIn['st'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'token' && !$auth) {
				if (NuInstall::locked()) $errors['token'] = t('ins_err_locked');
				else {
					$entered = trim(nu_post('token'));
					$ok = NuInstall::allowFileExists() || ($entered !== '' && NuInstall::checkToken($entered));
					if ($ok) { session_regenerate_id(true); $_SESSION[NuSess::DB_SETUP_AUTH] = true; nu_redirect(nu_url('dbsetup')); }
					$errors['token'] = t($entered === '' ? 'ins_err_token_empty' : 'ins_err_token');
				}
			} elseif ($auth && in_array($action, ['setup', 'repair', 'upgrade'], true) && !is_array($done)) {
				try {
					// an existing database (tables missing): the integrity check first – problems need the tick "go on anyway"
					if ($action === 'repair' && NuIntegrity::blocking(NuIntegrity::run(), ['tables']) && nu_check('integrity_ack') !== '1') throw new RuntimeException(t('ic_ack_needed'));
					if ($action === 'upgrade' && NuIntegrity::blocking(NuIntegrity::run(), ['tables']) && nu_check('integrity_ack') !== '1') throw new RuntimeException(t('ic_ack_needed'));
					if ($action === 'upgrade' && nu_check('backup_ack') !== '1') throw new RuntimeException(t('ins_backup_needed'));
					$choice = $action === 'upgrade' ? 'upgrade' : ($action === 'setup' && nu_post('choice') === 'upload' ? 'upload' : 'nunext');
					$file = null;
					if ($choice === 'upload') {
						$path = null;
						if (($_FILES['dump']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
							[$path, $upErr] = NuInstall::storeUpload($_FILES['dump']);
							if ($upErr !== '') throw new RuntimeException($upErr);
						} elseif (($pick = nu_post('dump_pick')) !== '' && isset(NuInstall::dumpCandidates()[$pick])) {
							$path = NuInstall::dumpCandidates()[$pick];
						}
						if ($path === null) throw new RuntimeException(t('ins_dump_err_none'));
						$chk = NuInstall::checkDumpFile($path);
						if (!$chk['ok']) throw new RuntimeException($chk['error']);
						$file = $chk['file'];
					}
					$r = NuDbState::setUp($choice, $file);
					foreach (glob(NU_ROOT . '/_install/nunext-lib-*.zip') ?: [] as $f) @rename($f, NuUpdate::folderDir() . '/' . basename($f));   // library packs stay offered
					NuInstall::cleanup();                                 // token, uploads, install.allow
					$_SESSION[NuSess::DB_SETUP_DONE] = ['state' => $st['state'], 'imported' => $r['imported'], 'upgraded' => $r['upgraded'], 'steps' => count($r['steps'])];
					unset($_SESSION[NuSess::DB_SETUP_AUTH]);
				} catch (Throwable $e) {
					error_log('nuBuilder Next database set-up: ' . $e->getMessage());
					nu_flash('danger', t('dbs_err_failed') . ' ' . $e->getMessage());
				}
				nu_redirect(nu_url('dbsetup'));
			}
		}
		return compact('auth', 'errors', 'done', 'st');
	}
}
