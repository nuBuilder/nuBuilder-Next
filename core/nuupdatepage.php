<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * The work of the update page (pages/nuupdate.php, code review R7): download of a backup and the POST actions – upload / check a
 * package, GitHub, install, backups, roll back, restore the database part, structure, maintenance, library packs. Every action ends with
 * nu_redirect() (post / redirect / get); an error becomes a message on the page it came from. The page itself only renders.
 */
final class NuUpdatePage
{
	/** The pending package of an upload id (16 hex characters), '' for anything else. */
	public static function uploadPath(string $id): string
	{
		return preg_match('/^[a-f0-9]{16}$/', $id) ? NuUpdate::backupDir() . '/upload-' . $id . '.zip' : '';
	}

	/** ?download=<backup>[&part=sql]: the whole backup or only its SQL dump (.sql.gz). */
	public static function download(): never
	{
		$f = NuUpdate::backupPath((string)$_GET['download']);
		if (!$f) { http_response_code(404); exit; }
		if (($_GET['part'] ?? '') === 'sql') {                                             // only the SQL dump (.sql.gz – e.g. for phpMyAdmin)
			$z = new ZipArchive();
			$st = $z->open($f) === true ? $z->getStream(NuUpdate::SQL_ENTRY) : false;
			if ($st === false) { http_response_code(404); exit; }
			$size = (int)($z->statName(NuUpdate::SQL_ENTRY)['size'] ?? 0);
			session_write_close();
			while (ob_get_level()) ob_end_clean();
			header('Content-Type: application/gzip');
			header('Content-Disposition: attachment; filename="' . basename($f, '.zip') . '.sql.gz"');
			if ($size) header('Content-Length: ' . $size);
			header('X-Content-Type-Options: nosniff');
			fpassthru($st);
			fclose($st);
			$z->close();
			NuLog::write('nunext_backup_sql_download', '', '', ['file' => basename($f)]);
			exit;
		}
		NuLog::write('nunext_backup_download', '', '', ['file' => basename($f)]);   // the whole backup (program files + database dump)
		session_write_close();
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . basename($f) . '"');
		header('Content-Length: ' . filesize($f));
		header('X-Content-Type-Options: nosniff');
		readfile($f);
		exit;
	}

	/** One POST action of the page. */
	public static function post(string $action): never
	{
		try {
			// $nuConfigEnableDatabaseUpdate = false: no update, rollback or structure change – checked here, not only in the page
			if (!NuUpdate::enabled() && in_array($action, ['upload', 'install', 'rollback', 'restore_db', 'schema_apply', 'github_download', 'github_branch'], true)) throw new RuntimeException(t('upd_disabled'));
			if (str_starts_with($action, 'lp_')) self::libraryPacks($action);
			match ($action) {
				'upload'          => self::upload(),
				'github_settings' => self::githubSettings(),
				'github_check'    => self::githubCheck(),
				'github_download' => self::githubDownload(),
				'github_branch' => self::githubBranch(),
				'folder_check'    => self::folderCheck(nu_post('name')),
				'install', 'discard' => self::install($action),
				'backup'          => self::backup(),
				'backup_keep'     => self::backupKeep(),
				'delete_backups'  => self::deleteBackups(),
				'backup_cleanup'  => self::backupCleanup(),
				'rollback', 'delete_backup' => self::rollback($action),
				'restore_db'      => self::restoreDb(),
				'schema_apply'    => self::schemaApply(),
				'maintenance_off' => self::maintenanceOff(),
				default           => nu_redirect(nu_url('update')),
			};
		} catch (Throwable $e) {
			error_log('nuBuilder Next update: ' . $e->getMessage());
			nu_flash('danger', $e->getMessage());
			nu_redirect(str_starts_with($action, 'lp_') ? nu_url('update', ['tab' => 'libraries']) . '#libraries'
				: ($action === 'restore_db' ? nu_url('update', ['tab' => 'backups']) . '#backups' : nu_url('update')));
		}
	}

	private static function upload(): never
	{
		$f = $_FILES['package'] ?? null;
		$err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
		if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException(t('upd_err_upload_size', NuUpdate::uploadLimitText()));
		if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException(t('upd_err_upload'));
		foreach (glob(NuUpdate::backupDir() . '/upload-*.zip') ?: [] as $old) @unlink($old);          // one pending package at a time
		$id = bin2hex(random_bytes(8));
		if (!move_uploaded_file($f['tmp_name'], self::uploadPath($id))) throw new RuntimeException(t('upd_err_upload'));
		@chmod(self::uploadPath($id), 0600);
		// "Check & install" (developer mode): checked as on the check page and installed at once – no SQL in the backup,
		// the safe database columns, sample data refreshed; anything that needs a decision goes to the normal check page instead
		if (nu_post('quick') === '1' && NuHash::devMode()) {
			$zip = self::uploadPath($id);
			$qi = NuUpdate::inspect($zip);
			$why = '';
			if (!$qi['ok']) $why = implode(' · ', $qi['errors']);
			elseif (!version_compare($qi['version'], NU_VERSION, '>')) $why = t('upd_err_not_newer', $qi['version']);
			elseif (in_array(false, array_column(NuUpdate::requirements(), 1), true)) $why = t('upd_quick_req');
			elseif (array_filter(NuLibs::unmet(NuLibs::required((array)$qi['manifest']), null, NuLibs::available()), static fn($x) => $x['needed'] && $x['have'] === '')) $why = t('upd_quick_libs');
			elseif (NuIntegrity::forPackage($zip, $qi['prefix'])['errors']) $why = t('upd_quick_integrity');
			if ($why !== '') {
				nu_flash('warning', t('upd_quick_stopped', $why));
				nu_redirect(nu_url('update', ['check' => $id]));
			}
			$exp = NuSchemaCheck::fromZip($zip, $qi['prefix']);
			$cmp = $exp ? NuSchemaCheck::compare($exp) : null;
			$_POST['schema'] = $cmp ? array_values(array_map(static fn($c) => (string)$c['key'],
				array_merge($cmp['missing'], array_values(array_filter($cmp['changed'], static fn($c) => $c['safe']))))) : [];
			$_POST['id'] = $id;
			$_POST['backup_db'] = 'none';
			$_POST['sample_refresh'] = NuSample::status()['installed'] ? '1' : '0';
			NuLog::write('nunext_update_quick', '', '', ['version' => $qi['version']]);
			self::install('install');
		} else {
			nu_redirect(nu_url('update', ['check' => $id]));
		}
	}

	// a package of the folder _update/: copied to the pending package and checked like an upload; only signed packages
	// GitHub (core/nugithub.php): settings, look for a newer release, download it into _update/ and check it from there
	private static function githubSettings(): never
	{
		$changed = NuGitHub::saveSettings(nu_post('repo'), nu_check('pre') === '1', nu_post('every'));
		nu_flash('success', $changed ? t('gh_saved') : t('aud_set_nochange'), true);
		nu_redirect(nu_url('update'));
	}

	// the answer as a toast: newer on GitHub / the same / the installed version is newer / nothing found / error
	private static function githubCheck(): never
	{
		$gs = NuGitHub::check();
		$gl = $gs['latest'];
		if ($gs['error'] !== '') nu_flash('danger', t('gh_err_check', $gs['error']), true);
		elseif (!$gl) nu_flash('warning', t('gh_err_none'), true);
		else {
			$cmp = version_compare((string)$gl['version'], NU_VERSION);
			nu_flash($cmp > 0 ? 'success' : 'info', $cmp > 0 ? t('gh_found', (string)$gl['version']) : ($cmp === 0 ? t('gh_uptodate', NU_VERSION) : t('gh_ahead', NU_VERSION, (string)$gl['version'])), true);
		}
		nu_redirect(nu_url('update'));
	}

	private static function githubBranch(): never
	{
		if (!NuUpdate::enabled()) throw new RuntimeException(t('upd_disabled'));
		self::folderCheck(NuGitHub::downloadBranch());                        // the branch main of the repository, checked like a release
	}

	private static function githubDownload(): never
	{
		if (!NuUpdate::enabled()) throw new RuntimeException(t('upd_disabled'));
		self::folderCheck(NuGitHub::download());                              // then checked like a package of the folder
	}

	private static function folderCheck(string $name): never
	{
		$src = NuUpdate::folderName($name) ? NuUpdate::folderDir() . '/' . $name : '';
		if ($src === '' || !is_file($src)) throw new RuntimeException(t('upd_folder_gone'));
		$p = array_values(array_filter(NuUpdate::folderPackages(), static fn($x) => $x['name'] === $name))[0] ?? null;
		if (!$p || !$p['ok']) throw new RuntimeException(t('upd_folder_unsigned', $name));
		foreach (glob(NuUpdate::backupDir() . '/upload-*.zip') ?: [] as $old) @unlink($old);
		$id = bin2hex(random_bytes(8));
		if (!@copy($src, self::uploadPath($id))) throw new RuntimeException(t('upd_err_upload'));
		@chmod(self::uploadPath($id), 0600);
		$_SESSION[NuSess::UPDATE_FOLDER] = ['id' => $id, 'name' => $name];        // deleted from the folder after a successful install
		NuLog::write('nunext_update_folder', '', '', ['file' => $name, 'version' => $p['version']]);
		nu_redirect(nu_url('update', ['check' => $id]));
	}

	private static function install(string $action): never
	{
		$id = nu_post('id');
		$zip = self::uploadPath($id);
		if ($zip === '' || !is_file($zip)) throw new RuntimeException(t('upd_err_upload'));
		if ($action === 'discard') { @unlink($zip); nu_redirect(nu_url('update')); }
		nu_ignore_abort();      // the install finishes even if the browser leaves the page (closed tab, other link)
		$info = NuUpdate::inspect($zip);
		if (!$info['ok']) throw new RuntimeException(implode(' · ', $info['errors']));
		if (version_compare($info['version'], NU_VERSION, '<=') && nu_check('reinstall') !== '1') throw new RuntimeException(t('upd_err_not_newer', $info['version']));
		// integrity check: problems that make an update fail or damage data need the tick "go on anyway"
		$ic = NuIntegrity::forPackage($zip, $info['prefix']);
		if ($ic['errors'] && nu_check('integrity_ack') !== '1') throw new RuntimeException(t('ic_ack_needed'));
		if ($ic['errors']) NuLog::write('integrity_ignored', '', '', ['version' => $info['version'], 'items' => array_column($ic['items'], 'id')]);
		// database structure: the columns the globeadmin selected (missing / other type), before the files are replaced
		$schemaDone = [];
		$sel = array_values(array_filter(array_map('strval', is_array($_POST['schema'] ?? null) ? $_POST['schema'] : [])));
		if ($sel && ($exp = NuSchemaCheck::fromZip($zip, $info['prefix']))) {
			$sr = NuSchemaCheck::apply($exp, $sel);
			$schemaDone = $sr['done'];
			if ($sr['errors']) throw new RuntimeException(t('upd_schema_failed') . ' ' . implode(' · ', $sr['errors'])
				. ($sr['done'] ? ' ' . t('upd_schema_done', implode(', ', $sr['done'])) : ''));
		}
		$bkDb = in_array(nu_post('backup_db'), ['nubuilder', 'all'], true) ? nu_post('backup_db') : 'none';   // nuBuilder tables by default
		$backup = NuUpdate::install($zip, $info, $bkDb, nu_check('backup_nologs') === '1', nu_check('backup_pages') === '1');
		if ($schemaDone && isset($_SESSION[NuSess::UPDATE])) $_SESSION[NuSess::UPDATE]['schema'] = $schemaDone;
		// sample data: refreshed after the update, with the new code (finish request)
		if (nu_check('sample_refresh') === '1' && isset($_SESSION[NuSess::UPDATE])) $_SESSION[NuSess::UPDATE]['sample_refresh'] = true;
		@unlink($zip);
		$fromFolder = $_SESSION[NuSess::UPDATE_FOLDER] ?? null;                         // installed from _update/: the zip there goes too
		if (is_array($fromFolder) && ($fromFolder['id'] ?? '') === $id && NuUpdate::folderName((string)$fromFolder['name'])) @unlink(NuUpdate::folderDir() . '/' . $fromFolder['name']);
		unset($_SESSION[NuSess::UPDATE_FOLDER]);
		nu_redirect(nu_url('update', ['finish' => '1']));                         // next request runs the new code
	}

	private static function backup(): never
	{
		// once per page view: a double click (or a resent form) must not make a second backup – the token of the page is used up
		$once = nu_post('once');
		if ($once === '' || !isset($_SESSION[NuSess::BACKUP_ONCE][$once])) {
			nu_flash('info', t('upd_backup_twice'));
			nu_redirect(nu_url('update', ['tab' => 'backups']) . '#backups');
		}
		unset($_SESSION[NuSess::BACKUP_ONCE][$once]);
		// never two at the same time (two tabs, two globeadmins): NuUpdate::backup() locks and says so
		$scope = in_array(nu_post('sql'), ['nubuilder', 'all'], true) ? nu_post('sql') : 'none';
		$name = NuUpdate::backup('manual', $scope, nu_check('nologs') === '1', nu_check('pages') === '1');
		$sql = NuUpdate::$lastSql;
		NuLog::write('nunext_backup', '', '', ['file' => $name, 'sql' => $sql ? ['scope' => $scope, 'complete' => $sql['complete'], 'bytes' => $sql['bytes']] : 'none']);
		nu_flash('success', t('upd_backup_done', $name));
		if ($sql && $sql['complete']) nu_flash('success', t('upd_sql_done', $sql['tables'], number_format((int)$sql['rows'], 0, '.', "'"), NuFile::size((int)$sql['bytes'])));
		elseif ($sql) nu_flash('danger', t('upd_sql_incomplete', $sql['error'] !== '' ? $sql['error'] : '?'));
		nu_redirect(nu_url('update', ['tab' => 'backups']) . '#backups');
	}

	private static function backupKeep(): never
	{
		NuUpdate::setKeepSql((int)nu_post('keep'));
		nu_flash('success', t('upd_keep_saved'));
		nu_redirect(nu_url('update', ['tab' => 'backups']) . '#backups');
	}

	/** several at once; newest + safety backup kept */
	private static function deleteBackups(): never
	{
		$names = is_array($_POST['names'] ?? null) ? array_values(array_filter($_POST['names'], 'is_string')) : [];
		$res = NuUpdate::deleteBackups($names);
		nu_flash($res['deleted'] ? 'success' : 'warning', $res['deleted'] ? t('upd_bk_deleted_n', count($res['deleted']), NuFile::size($res['bytes'])) : t('upd_bk_deleted_none'));
		$pg = (int)nu_post('pg');
		nu_redirect(nu_url('update', ['pg' => $pg > 1 ? $pg : null, 'tab' => 'backups']) . '#backups');
	}

	/** clean-up rule: save it, and with "Delete now" apply it */
	private static function backupCleanup(): never
	{
		$mode = nu_post('mode') === 'age' ? 'age' : 'keep';
		$n = max(1, min(9999, (int)nu_post($mode === 'age' ? 'days' : 'keep_n')));
		NuUpdate::setCleanupRule($mode, $n, nu_check('auto') === '1');
		if (nu_post('run') === '1') {
			$res = NuUpdate::deleteBackups(array_column(NuUpdate::cleanupPlan($mode, $n), 'name'), 'rule');
			nu_flash($res['deleted'] ? 'success' : 'info', $res['deleted'] ? t('upd_bk_deleted_n', count($res['deleted']), NuFile::size($res['bytes'])) : t('upd_bk_clean_nothing'));
		} else {
			nu_flash('success', t('saved'));
		}
		nu_redirect(nu_url('update', ['tab' => 'backups']) . '#backups');
	}

	private static function rollback(string $action): never
	{
		$f = NuUpdate::backupPath(nu_post('name'));
		if (!$f) throw new RuntimeException(t('upd_err_backup_invalid'));
		if ($action === 'delete_backup') {
			$sz = (int)@filesize($f);
			if (@unlink($f)) NuLog::write('nunext_backups_deleted', '', '', ['how' => 'single', 'count' => 1, 'bytes' => $sz, 'files' => [basename($f)]]);   // logged
			nu_flash('success', t('upd_backup_deleted'));
			$pg = (int)nu_post('pg');
			nu_redirect(nu_url('update', ['pg' => $pg > 1 ? $pg : null, 'tab' => 'backups']) . '#backups');         // stay on the page of the backup list
		}
		$safety = NuUpdate::backup('before rollback');                              // the current state can be restored again
		NuUpdate::maintenanceOn('rollback');
		try {
			$r = NuUpdate::restoreFiles($f);
		} finally {
			NuUpdate::maintenanceOff();
		}
		NuLog::write('nunext_rollback', '', '', ['from' => NU_VERSION, 'to' => $r['version'], 'backup' => basename($f), 'safety' => $safety]);
		nu_flash('success', t('upd_rollback_done', $r['version'], $safety));
		nu_redirect(nu_url('update', ['tab' => 'backups']) . '#backups');
	}

	/** database part of a backup back: globeadmin only */
	private static function restoreDb(): never
	{
		if (empty(NuAuth::user()['globeadmin'])) throw new RuntimeException(t('uf_edit_globeadmin'));
		$f = NuUpdate::backupPath(nu_post('name'));
		if (!$f) throw new RuntimeException(t('upd_err_backup_invalid'));
		$r = NuSqlDump::restore($f, true);   // always with a backup first: the way back if the restore fails
		nu_flash('success', t('rst_done', $r['version'], $r['statements'], count($r['steps'])));
		if ($r['mismatch']) nu_flash('warning',
			t('rst_verify_diff', count($r['mismatch']), implode(', ', array_map(static fn($t, $c) => $t . ' ' . $c[0] . ' → ' . $c[1], array_keys($r['mismatch']), $r['mismatch']))));
		elseif ($r['checked']) nu_flash('success', t('rst_verify_ok', $r['checked']));
		if ($r['safety'] !== '') nu_flash('info', t('rst_done_safety', $r['safety']));
		nu_redirect(nu_url('update', ['tab' => 'backups']) . '#backups');
	}

	private static function schemaApply(): never
	{
		$exp = NuSchemaCheck::installed();
		if (!$exp) throw new RuntimeException(t('upd_schema_nofile'));
		$sr = NuSchemaCheck::apply($exp, array_map('strval', is_array($_POST['schema'] ?? null) ? $_POST['schema'] : []));
		if ($sr['done']) nu_flash('success', t('upd_schema_done', implode(', ', $sr['done'])));
		if ($sr['errors']) nu_flash('danger', t('upd_schema_failed') . ' ' . implode(' · ', $sr['errors']));
		if (!$sr['done'] && !$sr['errors']) nu_flash('info', t('upd_schema_nothing'));
		nu_redirect(nu_url('update', ['tab' => 'system']) . '#system');
	}

	private static function maintenanceOff(): never
	{
		NuUpdate::maintenanceOff();
		nu_redirect(nu_url('update'));
	}

	// library packs (core/nulibs.php): tab Libraries – install / update (one or the ticked ones), upload, roll back,
	// remove, self-test, look on GitHub. Every pack needs a valid signature.
	private static function libraryPacks(string $action): never
	{
		$back = nu_url('update', ['tab' => 'libraries']) . '#libraries';
		if (in_array($action, ['lp_install', 'lp_upload', 'lp_rollback', 'lp_remove', 'lp_remove_old'], true) && !NuUpdate::enabled()) throw new RuntimeException(t('upd_disabled'));
		$slots = array_values(array_intersect(array_map('strval', nu_post('slot') !== '' ? [nu_post('slot')] : (array)($_POST['slots'] ?? [])), array_keys(NuLibs::SLOTS)));
		if ($action === 'lp_gh_check') {
			$g = NuLibs::ghCheck();
			if ($g['error'] !== '') nu_flash('danger', t('lp_gh_error', $g['error']), true);
			else nu_flash('success', t('lp_gh_found', count($g['libs'])), true);
			nu_redirect($back);
		}
		if ($action === 'lp_upload') {
			$f = $_FILES['pack'] ?? null;
			$err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
			if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException(t('upd_err_upload_size', NuUpdate::uploadLimitText()));
			if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException(t('upd_err_upload'));
			$tmp = NuUpdate::folderDir() . '/.upload-' . bin2hex(random_bytes(6)) . '.zip';
			if (!move_uploaded_file($f['tmp_name'], $tmp)) throw new RuntimeException(t('upd_err_upload'));
			try {
				nu_ignore_abort();
				$r = NuLibs::install($tmp, 'upload');
			} finally {
				@unlink($tmp);
			}
			nu_flash('success', t('lp_installed', NuLibs::SLOTS[$r['slot']]['title'], $r['version']), true);
			nu_redirect($back);
		}
		if (!$slots) throw new RuntimeException(t('lp_err_none'));
		if ($action === 'lp_install') {
			nu_ignore_abort();
			$ov = NuLibs::overview();
			// packs from GitHub: read the index again first – the saved one may be older than the release's files (same names,
			// new sizes / checksums → "does not match the index"); on an error the saved index stays as before
			$fromGh = false;
			foreach ($slots as $sl) if (($ov[$sl]['best']['where'] ?? '') === 'github' || empty($ov[$sl]['best'])) $fromGh = true;
			if ($fromGh && NuLibs::ghState()['repo'] !== '') {
				$g = NuLibs::ghCheck();
				if ($g['error'] === '') $ov = NuLibs::overview(false, $g);
			}
			$done = $fail = [];
			foreach ($slots as $sl) {
				$best = $ov[$sl]['best'] ?? null;
				try {
					if (!$best) throw new RuntimeException(t('lp_err_nothing'));
					$r = NuLibs::installCandidate($best);
					$done[] = NuLibs::SLOTS[$sl]['title'] . ' ' . $r['version'];
				} catch (Throwable $e) {
					$fail[] = NuLibs::SLOTS[$sl]['title'] . ': ' . $e->getMessage();
				}
			}
			if ($done) nu_flash('success', t('lp_installed_list', implode(', ', $done)), true);
			foreach ($fail as $m) nu_flash('danger', $m);
			// from "Check package": back to the checked package
			if (preg_match('/^[a-f0-9]{16}$/', nu_post('back_check')) && is_file(self::uploadPath(nu_post('back_check')))) nu_redirect(nu_url('update', ['check' => nu_post('back_check')]));
			nu_redirect($back);
		}
		if ($action === 'lp_rollback') {
			foreach ($slots as $sl) nu_flash('success', t('lp_rolled_back', NuLibs::SLOTS[$sl]['title'], NuLibs::rollback($sl)), true);
			nu_redirect($back);
		}
		if ($action === 'lp_remove') {
			foreach ($slots as $sl) { NuLibs::remove($sl); nu_flash('success', t('lp_removed', NuLibs::SLOTS[$sl]['title']), true); }
			nu_redirect($back);
		}
		if ($action === 'lp_remove_old') {                 // the ticked older versions of one library
			$sl = $slots[0] ?? '';
			$gone = $sl !== '' ? NuLibs::removeOlder($sl, array_filter((array)($_POST['versions'] ?? []), 'is_string')) : [];
			if (!$gone) throw new RuntimeException(t('lp_remove_old_none'));
			nu_flash('success', t('lp_removed_old', NuLibs::SLOTS[$sl]['title'], implode(', ', $gone)), true);
			nu_redirect($back);
		}
		if ($action === 'lp_test') {
			$res = [];
			foreach ($slots as $sl) $res[$sl] = NuLibs::selfTest($sl);
			$_SESSION[NuSess::LIBPACK_TEST] = $res;
			NuLog::write('nunext_lib_test', 'third_party', implode(',', $slots), array_map(static fn($r) => $r[0], $res));
			nu_redirect($back);
		}
		throw new RuntimeException(t('lp_err_none'));
	}
}
