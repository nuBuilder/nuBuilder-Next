<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nudbreset.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuDbresetPage
{
	/** The POST actions of pages/nudbreset.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$S = &$_SESSION[NuSess::DB_RESET];                          // the page's $S: the session entry itself (redirects below end the request)
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'check') {
				try {
					if (!empty($S['path'])) NuDbReset::forgetUpload((string)$S['path']);   // an earlier check's upload
					if (!empty($_FILES['upload']['name'])) $path = NuDbReset::storeUpload($_FILES['upload']);
					else {
						$src = NuDbReset::sources();
						$path = $src[nu_post('source')] ?? null;
						if ($path === null) throw new RuntimeException(t('dbr_err_nofile'));
					}
					$S = ['path' => $path, 'check' => NuDbReset::check($path), 'backup' => nu_post('backup') === '1', 'sample' => nu_post('sample') === '1'];
				} catch (Throwable $e) {
					if (isset($path) && is_string($path)) NuDbReset::forgetUpload($path);
					$S = [];
					nu_flash('danger', $e->getMessage());
				}
			} elseif ($action === 'run' && !empty($S['check']['ok'])) {
				NuSecurity::requireRecentAuth(nu_url('dbreset'));
				if (trim(nu_post('confirm')) !== (string)$S['check']['database']) {
					nu_flash('danger', t('dbr_err_confirm'));
				} else {
					try {
						if (($S['check']['kind'] ?? '') === 'package') {
							NuDbReset::runPackage((string)$S['path'], nu_post('backup') === '1', nu_post('sample') === '1');
							NuDbReset::forgetUpload((string)$S['path']);
							$S = [];
							nu_redirect(nu_url('dbreset', ['fresh' => '1']));   // the next request runs the new code
						}
						// in steps (NuDbReset::step, assets/js/nudbreset.js) – one request for a large file outlasted the host's time limit (503)
						$S = ['job' => NuDbReset::startJob((string)$S['path'], nu_post('backup') === '1', nu_post('sample') === '1', (array)$S['check'])];
					} catch (Throwable $e) {
						error_log('nuBuilder Next reset database: ' . $e->getMessage());
						nu_flash('danger', t('dbr_err_failed', $e->getMessage()));
					}
				}
			} elseif ($action === 'cancel') {
				if (!empty($S['path'])) NuDbReset::forgetUpload((string)$S['path']);
				$S = [];
			}
			nu_redirect(nu_url('dbreset'));
		}
		return compact('S');
	}
}
