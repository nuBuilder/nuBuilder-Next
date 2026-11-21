<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nuinstall.php (code review R7: a page defines no global function). */
final class NuInstallPage
{
	/** Integrity check of the database the installer connects to (existing nuBuilder Forte database). */
	public static function integrity(array $S): array
	{
		try {
			NuDB::$override = NuInstall::connect($S['db']);
			return NuIntegrity::run();
		} catch (Throwable $e) {
			return ['items' => [['id' => 'failed', 'level' => 'info', 'list' => [$e->getMessage()], 'count' => 1]], 'errors' => 0, 'warnings' => 0, 'infos' => 1, 'checks' => 0, 'ms' => 0];
		} finally {
			NuDB::$override = null;
		}
	}

	/** After nuconfig.php exists: nuBuilder Next's tables and database steps, token removed. Returns the done data. */
	public static function finish(?array &$S): array
	{
		$out = ['imported' => (int)($S['imported'] ?? 0), 'upgraded' => $S['upgraded'] ?? null, 'steps' => [], 'error' => ''];
		try {
			NuSchema::allow();
			NuSchema::create();
			$existing = ($S['detect']['kind'] ?? '') === 'nubuilder';      // on an existing nuBuilder Forte database (marker file)
			NuInstall::setOrigin($existing ? 'nubuilder' : 'nunext');   // set up on a new database – step 0014 keeps it
			// safer defaults for NEW installations (security assessment C2 / K1): records only through the form's list, read-only
			// and hidden fields never saved from the browser – existing installations keep their settings (the Security check names them)
			if (!$existing) NuSecurity::save(['record_check' => true, 'protect_fields' => true]);
			NuWelcome::setLangs((array)($S['opt']['langs'] ?? []), true);   // the welcome e-mail templates only in the chosen languages
			$out['steps'] = NuMigrate::run();
		} catch (Throwable $e) {
			$out['error'] = $e->getMessage();
		}
		$out['libs'] = NuInstallPage::libs($S);
		NuInstall::cleanup();
		$out['admin'] = $S['admin']['user'];
		$S = null;
		unset($_SESSION[NuSess::INSTALL]);
		return $out;
	}

	/**
	 * Installs the library packs ticked on the step "Libraries" – a failed one does not stop the installation (the update page
	 * can install it later). Packs left in _install/ move to _update/, so the update page still offers them.
	 * @return list<array{0: string, 1: string, 2: bool, 3: string}> title, version, ok, error
	 */
	public static function libs(?array $S): array
	{
		$out = [];
		$want = (array)($S['libs'] ?? []);
		if ($want) {
			try {
				$ov = NuLibs::overview(true, $S['libs_gh'] ?? ['libs' => []], (string)($S['db']['type'] ?? 'mysql'));
			} catch (Throwable $e) {
				return [[t('upd_libs'), '', false, $e->getMessage()]];
			}
			foreach ($want as $sl) {
				$best = $ov[$sl]['best'] ?? null;
				try {
					if (!$best) throw new RuntimeException(t('lp_err_nothing'));
					$r = NuLibs::installCandidate($best, 'installer');
					$out[] = [NuLibs::SLOTS[$sl]['title'], $r['version'], true, ''];
				} catch (Throwable $e) {
					error_log('nuBuilder Next install, library ' . $sl . ': ' . $e->getMessage());
					$out[] = [NuLibs::SLOTS[$sl]['title'], (string)($best['version'] ?? ''), false, $e->getMessage()];
				}
			}
		}
		foreach (glob(NU_ROOT . '/_install/nunext-lib-*.zip') ?: [] as $f) @rename($f, NuUpdate::folderDir() . '/' . basename($f));
		return $out;
	}

	/** The POST actions of pages/nuinstall.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		if (nu_is_post()) nu_csrf_verify();
		return [];
	}
}
