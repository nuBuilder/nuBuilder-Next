<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Update nuBuilder Next (globeadmin): upload a package → check and preview → install (backup, maintenance mode, copy, clean up)
 * → finish with the new code (database steps). Backups: download, roll back, delete. See core/nuupdate.php.
 *   ?p=update · ?p=update&check=<upload id> · ?p=update&finish=1 · ?p=update&download=<backup>
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('update');
NuSecurity::requireRecentAuth(nu_url('update'));   // password again when the last confirmation is older than 15 minutes (0.8.207)

$dir = NuUpdate::backupDir();
$uploadPath = static fn(string $id) => preg_match('/^[a-f0-9]{16}$/', $id) ? $dir . '/upload-' . $id . '.zip' : '';

// ------------------------------------------------------------------ download a backup
if (isset($_GET['download'])) {
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

// ------------------------------------------------------------------ actions
if (nu_is_post()) {
	$action = nu_post('action');
	try {
		// $nuConfigEnableDatabaseUpdate = false: no update, rollback or structure change (0.8.281) – checked here, not only in the page
		if (!NuUpdate::enabled() && in_array($action, ['upload', 'install', 'rollback', 'schema_apply', 'github_download'], true)) throw new RuntimeException(t('upd_disabled'));
		if ($action === 'upload') {
			$f = $_FILES['package'] ?? null;
			$err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
			if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new RuntimeException(t('upd_err_upload_size', NuUpdate::uploadLimitText()));
			if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException(t('upd_err_upload'));
			foreach (glob($dir . '/upload-*.zip') ?: [] as $old) @unlink($old);          // one pending package at a time
			$id = bin2hex(random_bytes(8));
			if (!move_uploaded_file($f['tmp_name'], $uploadPath($id))) throw new RuntimeException(t('upd_err_upload'));
			@chmod($uploadPath($id), 0600);
			// "Check & install" (0.8.733, developer mode): checked as on the check page and installed at once – no SQL in the backup,
			// the safe database columns, sample data refreshed; anything that needs a decision goes to the normal check page instead
			if (nu_post('quick') === '1' && NuHash::devMode()) {
				$zip = $uploadPath($id);
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
				$action = 'install';
			} else {
				nu_redirect(nu_url('update', ['check' => $id]));
			}
		}
		// a package of the folder _update/ (0.8.372): copied to the pending package and checked like an upload; only signed packages
		// GitHub (0.8.374, core/nugithub.php): settings, look for a newer release, download it into _update/ and check it from there
		if ($action === 'github_settings') {
			$changed = NuGitHub::saveSettings(nu_post('repo'), nu_check('pre') === '1', nu_post('every'));
			nu_flash('success', $changed ? t('gh_saved') : t('aud_set_nochange'), true);
			nu_redirect(nu_url('update'));
		}
		// the answer as a toast (0.8.377): newer on GitHub / the same / the installed version is newer / nothing found / error
		if ($action === 'github_check') {
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
		if ($action === 'github_download') {
			if (!NuUpdate::enabled()) throw new RuntimeException(t('upd_disabled'));
			$_POST['name'] = NuGitHub::download();                              // then checked like a package of the folder
			$action = 'folder_check';
		}
		if ($action === 'folder_check') {
			$name = nu_post('name');
			$src = NuUpdate::folderName($name) ? NuUpdate::folderDir() . '/' . $name : '';
			if ($src === '' || !is_file($src)) throw new RuntimeException(t('upd_folder_gone'));
			$p = array_values(array_filter(NuUpdate::folderPackages(), static fn($x) => $x['name'] === $name))[0] ?? null;
			if (!$p || !$p['ok']) throw new RuntimeException(t('upd_folder_unsigned', $name));
			foreach (glob($dir . '/upload-*.zip') ?: [] as $old) @unlink($old);
			$id = bin2hex(random_bytes(8));
			if (!@copy($src, $uploadPath($id))) throw new RuntimeException(t('upd_err_upload'));
			@chmod($uploadPath($id), 0600);
			$_SESSION['nu_upd_folder'] = ['id' => $id, 'name' => $name];        // deleted from the folder after a successful install
			NuLog::write('nunext_update_folder', '', '', ['file' => $name, 'version' => $p['version']]);
			nu_redirect(nu_url('update', ['check' => $id]));
		}
		if ($action === 'install' || $action === 'discard') {
			$id = nu_post('id');
			$zip = $uploadPath($id);
			if ($zip === '' || !is_file($zip)) throw new RuntimeException(t('upd_err_upload'));
			if ($action === 'discard') { @unlink($zip); nu_redirect(nu_url('update')); }
			nu_ignore_abort();      // the install finishes even if the browser leaves the page (closed tab, other link)
			$info = NuUpdate::inspect($zip);
			if (!$info['ok']) throw new RuntimeException(implode(' · ', $info['errors']));
			if (version_compare($info['version'], NU_VERSION, '<=') && nu_check('reinstall') !== '1') throw new RuntimeException(t('upd_err_not_newer', $info['version']));
			// integrity check (0.8.698): problems that make an update fail or damage data need the tick "go on anyway"
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
			$bkDb = in_array(nu_post('backup_db'), ['nubuilder', 'all'], true) ? nu_post('backup_db') : 'none';   // 0.8.615: nuBuilder tables by default
			$backup = NuUpdate::install($zip, $info, $bkDb, nu_check('backup_nologs') === '1', nu_check('backup_pages') === '1');
			if ($schemaDone && isset($_SESSION['nu_update'])) $_SESSION['nu_update']['schema'] = $schemaDone;
			// sample data: refreshed after the update, with the new code (finish request)
			if (nu_check('sample_refresh') === '1' && isset($_SESSION['nu_update'])) $_SESSION['nu_update']['sample_refresh'] = true;
			@unlink($zip);
			$fromFolder = $_SESSION['nu_upd_folder'] ?? null;                         // installed from _update/: the zip there goes too (0.8.372)
			if (is_array($fromFolder) && ($fromFolder['id'] ?? '') === $id && NuUpdate::folderName((string)$fromFolder['name'])) @unlink(NuUpdate::folderDir() . '/' . $fromFolder['name']);
			unset($_SESSION['nu_upd_folder']);
			nu_redirect(nu_url('update', ['finish' => '1']));                         // next request runs the new code
		}
		if ($action === 'backup') {
			// once per page view: a double click (or a resent form) must not make a second backup – the token of the page is used up
			$once = nu_post('once');
			if ($once === '' || !isset($_SESSION['nu_backup_once'][$once])) {
				nu_flash('info', t('upd_backup_twice'));
				nu_redirect(nu_url('update', ['tab' => 'backups']) . '#backups');
			}
			unset($_SESSION['nu_backup_once'][$once]);
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
		if ($action === 'backup_keep') {
			NuUpdate::setKeepSql((int)nu_post('keep'));
			nu_flash('success', t('upd_keep_saved'));
			nu_redirect(nu_url('update', ['tab' => 'backups']) . '#backups');
		}
		if ($action === 'delete_backups') {                                         // several at once (0.8.451); newest + safety backup kept
			$names = is_array($_POST['names'] ?? null) ? array_values(array_filter($_POST['names'], 'is_string')) : [];
			$res = NuUpdate::deleteBackups($names);
			nu_flash($res['deleted'] ? 'success' : 'warning', $res['deleted'] ? t('upd_bk_deleted_n', count($res['deleted']), NuFile::size($res['bytes'])) : t('upd_bk_deleted_none'));
			$pg = (int)nu_post('pg');
			nu_redirect(nu_url('update', ['pg' => $pg > 1 ? $pg : null, 'tab' => 'backups']) . '#backups');
		}
		if ($action === 'backup_cleanup') {                                         // clean-up rule: save it, and with "Delete now" apply it (0.8.451)
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
		if ($action === 'rollback' || $action === 'delete_backup') {
			$f = NuUpdate::backupPath(nu_post('name'));
			if (!$f) throw new RuntimeException(t('upd_err_backup_invalid'));
			if ($action === 'delete_backup') {
				$sz = (int)@filesize($f);
				if (@unlink($f)) NuLog::write('nunext_backups_deleted', '', '', ['how' => 'single', 'count' => 1, 'bytes' => $sz, 'files' => [basename($f)]]);   // logged since 0.8.451
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
		if ($action === 'schema_apply') {
			$exp = NuSchemaCheck::installed();
			if (!$exp) throw new RuntimeException(t('upd_schema_nofile'));
			$sr = NuSchemaCheck::apply($exp, array_map('strval', is_array($_POST['schema'] ?? null) ? $_POST['schema'] : []));
			if ($sr['done']) nu_flash('success', t('upd_schema_done', implode(', ', $sr['done'])));
			if ($sr['errors']) nu_flash('danger', t('upd_schema_failed') . ' ' . implode(' · ', $sr['errors']));
			if (!$sr['done'] && !$sr['errors']) nu_flash('info', t('upd_schema_nothing'));
			nu_redirect(nu_url('update', ['tab' => 'system']) . '#system');
		}
		if ($action === 'maintenance_off') {
			NuUpdate::maintenanceOff();
			nu_redirect(nu_url('update'));
		}
		// library packs (0.8.405, core/nulibs.php): tab Libraries – install / update (one or the ticked ones), upload, roll back,
		// remove, self-test, look on GitHub. Every pack needs a valid signature.
		if (str_starts_with($action, 'lp_')) {
			$back = nu_url('update', ['tab' => 'libraries']) . '#libraries';
			if (in_array($action, ['lp_install', 'lp_upload', 'lp_rollback', 'lp_remove'], true) && !NuUpdate::enabled()) throw new RuntimeException(t('upd_disabled'));
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
				// packs from GitHub: read the index again first (0.8.556) – the saved one may be older than the release's files (same names,
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
				// from "Check package" (0.8.406): back to the checked package
				if (preg_match('/^[a-f0-9]{16}$/', nu_post('back_check')) && is_file($uploadPath(nu_post('back_check')))) nu_redirect(nu_url('update', ['check' => nu_post('back_check')]));
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
			if ($action === 'lp_test') {
				$res = [];
				foreach ($slots as $sl) $res[$sl] = NuLibs::selfTest($sl);
				$_SESSION['nu_lp_test'] = $res;
				NuLog::write('nunext_lib_test', 'third_party', implode(',', $slots), array_map(static fn($r) => $r[0], $res));
				nu_redirect($back);
			}
			throw new RuntimeException(t('lp_err_none'));
		}
	} catch (Throwable $e) {
		error_log('nuBuilder Next update: ' . $e->getMessage());
		nu_flash('danger', $e->getMessage());
		nu_redirect(str_starts_with((string)$action, 'lp_') ? nu_url('update', ['tab' => 'libraries']) . '#libraries' : nu_url('update'));
	}
}

// unattended update (command line / API) whose database steps are still pending: finish it now (0.8.301)
if (!nu_is_post()) { try { NuGitHub::autoCheck(true); } catch (Throwable) { /* shown in the GitHub section */ } }
if (!nu_is_post()) { try { NuAutoUpdate::finishPending(); } catch (Throwable $e) { error_log('nuBuilder Next update job: ' . $e->getMessage()); } }

// ------------------------------------------------------------------ finish (new code): database steps, maintenance off
$finished = null;
if (($_GET['finish'] ?? '') === '1' && isset($_SESSION['nu_update'])) {
	$finished = $_SESSION['nu_update'];
	unset($_SESSION['nu_update']);
	try {
		$finished['steps'] = NuMigrate::run();
		$finished['ok'] = true;
		if (($hookErr = NuUpdate::afterUpdateHook()) !== '') nu_flash('warning', 'nu_after_update: ' . $hookErr);   // nuBuilder's hook (0.8.462)
		if (!empty($finished['sample_refresh'])) {          // "Update the sample data too" (0.8.300): same as Sample data → Refresh
			try {
				$_SESSION['nu_sample_pw'] = NuSample::install();
				$finished['sample'] = NuSample::VERSION;
			} catch (Throwable $e) {
				error_log('nuBuilder Next sample data after update: ' . $e->getMessage());
				$finished['sample_error'] = $e->getMessage();
			}
		}
	} catch (Throwable $e) {
		$finished['ok'] = false;
		$finished['error'] = $e->getMessage();
	}
	NuUpdate::maintenanceOff();
	unset($_SESSION['nu_schema']);
}

$check = null;
if (isset($_GET['check']) && ($zip = $uploadPath((string)$_GET['check'])) !== '' && is_file($zip)) {
	$check = NuUpdate::inspect($zip) + ['id' => (string)$_GET['check']];
	if ($check['ok']) {                                     // what changes: new, changed, unchanged, removed
		$cur = NuUpdate::installedManifest();
		$diff = ['new' => 0, 'changed' => 0, 'same' => 0, 'removed' => []];
		foreach ($check['manifest']['files'] as $rel => $f) {
			$path = NU_ROOT . '/' . $rel;
			if (!is_file($path)) $diff['new']++;
			elseif (hash_file('sha256', $path) === $f['sha256']) $diff['same']++;
			else $diff['changed']++;
		}
		foreach (array_unique(array_merge($cur ? array_keys($cur['files']) : [], (array)($check['manifest']['obsolete'] ?? []))) as $rel) {
			if (!isset($check['manifest']['files'][$rel]) && is_file(NU_ROOT . '/' . $rel) && !NuUpdate::isProtected((string)$rel)
				&& !NuUpdate::isLibrary((string)$rel)) $diff['removed'][] = (string)$rel;
		}
		$check['diff'] = $diff;
		$smp = NuSample::status();                           // installed sample data: offer to update it as well
		$check['sample'] = $smp['installed'] ? ['from' => $smp['version'], 'to' => NuSample::versionInZip($zip, $check['prefix'])] : null;
		$exp = NuSchemaCheck::fromZip($zip, $check['prefix']);
		$check['schema'] = $exp ? NuSchemaCheck::compare($exp) : null;
		$check['integrity'] = NuIntegrity::forPackage($zip, $check['prefix']);   // 0.8.698
		// database changes of this update (0.8.346): pending steps, own tables, nuBuilder columns, sample data – with the SQL
		$smpTo = $check['sample'] ? (int)$check['sample']['to'] : 0;
		// page forms (0.8.388): which pages the new version's page API would break or warn about (manifest page_api)
		$check['pages'] = isset($check['manifest']['page_api']) && is_array($check['manifest']['page_api']) ? NuPage::report($check['manifest']['page_api']) : null;
		$check['dbc'] = NuDbChanges::preview(NuDbChanges::fromZip($zip, $check['prefix']), $exp, $check['schema'],
			$check['sample'] && $smpTo > (int)$check['sample']['from'] ? ['from' => $check['sample']['from'], 'to' => $smpTo] : null);
	}
}

/** List of column differences; with $formId the changes can be selected (checkboxes belong to that form). */
$schemaList = static function (?array $cmp, string $formId): string {
	if ($cmp === null) return '<p class="small text-body-secondary mb-0">' . h(t('upd_schema_nofile')) . '</p>';
	$safe = array_merge($cmp['missing'], array_values(array_filter($cmp['changed'], static fn($c) => $c['safe'])));
	$risky = array_values(array_filter($cmp['changed'], static fn($c) => !$c['safe']));
	$box = static function (array $it, bool $on) use ($formId): string {
		$id = 'sc_' . substr(md5($formId . $it['key']), 0, 10);
		$label = '<code>' . h($it['table']) . '.' . h($it['column']) . '</code> '
			. (isset($it['actual']) ? '<span class="text-body-secondary">' . h($it['actual']) . ' → </span><b>' . h($it['expected']) . '</b>' : '<span class="badge text-bg-success-subtle text-success-emphasis border">' . h(t('upd_schema_add')) . '</span> <b>' . h($it['type']) . '</b>');
		return '<div class="form-check"><input class="form-check-input" type="checkbox" name="schema[]" form="' . h($formId) . '" value="' . h($it['key']) . '" id="' . $id . '"' . ($on ? ' checked' : '') . '>'
			. '<label class="form-check-label small" for="' . $id . '">' . $label . '</label></div>';
	};
	$html = '';
	if (!$safe && !$risky) $html .= '<p class="small text-success mb-0"><i class="bi bi-check-circle me-1"></i>' . h(t('upd_schema_ok')) . '</p>';
	if ($safe) {
		$html .= '<p class="small mb-1">' . h(t('upd_schema_intro', count($safe))) . '</p>';
		foreach ($safe as $it) $html .= $box($it, true);
	}
	if ($risky) {
		$html .= '<details class="mt-2"><summary class="small">' . h(t('upd_schema_risky', count($risky))) . '</summary>'
			. '<div class="small text-warning-emphasis my-1"><i class="bi bi-exclamation-triangle me-1"></i>' . h(t('upd_schema_risky_hint')) . '</div>';
		foreach ($risky as $it) $html .= $box($it, false);
		$html .= '</details>';
	}
	if (!$cmp['types']) $html .= '<p class="small text-body-secondary mt-2 mb-0">' . h(t('upd_schema_types_mysql')) . '</p>';
	// tables only nuBuilder uses (version info, its report designer's source list, its time zone list) – nuBuilder Next works without them (0.8.278)
	$nubOnly = array_values(array_intersect($cmp['tables_missing'], ['zzzzsys_info', 'zzzzsys_report_data', 'zzzzsys_timezone']));
	$missing = array_values(array_diff($cmp['tables_missing'], $nubOnly));
	if ($missing) $html .= '<details class="mt-2"><summary class="small text-body-secondary">' . h(t('upd_schema_tables_missing', count($missing))) . '</summary><code class="small">' . h(implode(', ', $missing)) . '</code></details>';
	if ($nubOnly) $html .= '<details class="mt-2"><summary class="small text-body-secondary">' . h(t('upd_schema_tables_nub_only', count($nubOnly))) . '</summary><code class="small">' . h(implode(', ', $nubOnly)) . '</code>'
		. '<div class="small text-body-secondary mt-1"><i class="bi bi-check2-circle text-success me-1"></i>' . h(t('upd_schema_tables_nub_only_hint')) . '</div></details>';
	return $html;
};

$req = NuUpdate::requirements();
$reqOk = !in_array(false, array_column($req, 1), true);
$maint = NuUpdate::maintenance();
/** Database changes of an update, grouped by table, with the SQL (0.8.346, core/nudbchanges.php). */
$dbcList = static function (?array $p): string {
	if ($p === null) return '';
	$kinds = ['table' => ['bi-table', 'text-bg-success'], 'column' => ['bi-layout-three-columns', 'text-bg-primary'], 'type' => ['bi-arrow-left-right', 'text-bg-warning'], 'data' => ['bi-database', 'text-bg-secondary']];
	$h = '';
	if (!$p['described']) $h .= '<p class="small text-body-secondary mb-2" id="updDbcUndescribed"><i class="bi bi-info-circle me-1"></i>' . h(t('upd_dbc_undescribed')) . '</p>';
	// columns whose type is only different because the database has a larger one: not changed (unless ticked in "Database structure")
	$kept = $p['kept'] ? '<details class="small mt-2" id="updDbcKept"><summary class="text-body-secondary">' . h(t('upd_dbc_kept', count($p['kept']))) . '</summary>'
		. '<div class="text-body-secondary my-1">' . h(t('upd_dbc_kept_hint')) . '</div><code class="d-block">' . nl2br(h(implode("\n", $p['kept']))) . '</code></details>' : '';
	if ($p['none']) return $h . '<p class="small text-success mb-0" id="updDbcNone"><i class="bi bi-check-circle me-1"></i>' . h(t('upd_dbc_none')) . '</p>' . $kept;
	if ($p['steps']) {
		$h .= '<div class="small mb-2"><span class="fw-semibold">' . h(t('upd_dbc_steps', count($p['steps']))) . '</span><ul class="mb-0">';
		foreach ($p['steps'] as $st) $h .= '<li><code>' . h($st['id']) . '</code> ' . h($st['description']) . '</li>';
		$h .= '</ul></div>';
	}
	$h .= '<div class="small fw-semibold mb-1">' . h(t('upd_dbc_by_table', count($p['by_table']))) . '</div><ul class="list-unstyled small mb-0" id="updDbcTables">';
	foreach ($p['by_table'] as $table => $list) {
		$h .= '<li class="mb-2"><code class="fw-semibold">' . h($table) . '</code><ul class="list-unstyled ms-3 mt-1">';
		foreach ($list as $it) {
			[$icon, $bg] = $kinds[$it['kind']] ?? $kinds['data'];
			$h .= '<li class="mb-1"><span class="badge ' . $bg . ' me-1"><i class="bi ' . $icon . ' me-1"></i>' . h(t('upd_dbc_kind_' . $it['kind'])) . '</span>' . h($it['what'])
				. ($it['step'] !== '' ? ' <span class="text-body-secondary">· ' . h(t('upd_dbc_by_step', $it['step'])) . '</span>' : '')
;
			if ($it['sql']) $h .= '<details class="mt-1"><summary class="text-body-secondary">' . h(t('upd_dbc_sql')) . '</summary><pre class="small mb-0 p-2 bg-body-tertiary rounded nuDbcSql">' . h(implode(";\n", $it['sql']) . ';') . '</pre></details>';
			$h .= '</li>';
		}
		$h .= '</ul></li>';
	}
	return $h . '</ul>' . $kept;
};

$changes = static function (array $list): string {
	$html = '';
	foreach ($list as $c) {
		$html .= '<h3 class="h6 mt-3 mb-1">' . h($c['version'] ?? '') . (isset($c['title']) && $c['title'] !== '' ? ' – ' . h($c['title']) : '') . '</h3>'
			. '<div class="nuUpdChanges small">' . NuUpdate::changeHtml((string)($c['text'] ?? '')) . '</div>';
	}
	return $html;
};

$hasPages = (bool)NuUpdate::customFiles();     // page forms (0.8.387): "Include custom pages" in backups
nu_layout_start(t('upd_title'), 'home');
?>
<?= nu_page_header(t('upd_title'), ['icon' => 'bi-cloud-arrow-down', 'sub_html' => h(t('upd_installed', NU_VERSION)) . (NuUpdate::installedManifest() ? '' : ' · ' . h(t('upd_no_manifest')))]) ?>

<?php $updOn = NuUpdate::enabled(); if (!$updOn): ?>
	<div class="alert alert-warning d-flex gap-2" role="alert" id="updDisabled"><i class="bi bi-lock"></i><span><?= h(t('upd_disabled')) ?></span></div>
<?php endif; ?>

<?php if ($broken = NuUpdate::interrupted()): // the last backup was stopped by the server (time limit …) – no backup was saved ?>
	<div class="alert alert-danger d-flex gap-2 small" role="alert"><i class="bi bi-exclamation-octagon"></i>
		<span><?= h(t('upd_backup_interrupted', !empty($broken['since']) ? date(nu_dfmt('d.m.Y H:i'), (int)$broken['since']) : '?')) ?><?= ($broken['sql'] ?? 'none') !== 'none' ? ' ' . h(t('upd_backup_interrupted_sql')) : '' ?></span></div>
<?php endif; ?>

<?php if ($maint): ?>
	<div class="alert alert-warning d-flex flex-wrap gap-2 align-items-center">
		<i class="bi bi-cone-striped"></i><span class="me-auto"><?= h(t('upd_maintenance_active', date('H:i', (int)$maint['until']))) ?></span>
		<form method="post" action="<?= h(nu_url('update')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="maintenance_off">
			<button class="btn btn-sm btn-warning" type="submit"><?= h(t('upd_maintenance_end')) ?></button></form>
	</div>
<?php endif; ?>

<?php // tabs (0.8.392): Update · Backups · History · System – the tab is part of the address (#backups …), assets/js/nuapp.js
$tabBackups = count(NuUpdate::backups());
$tabLibs = NuLibraries::all();
$tabLibProblems = count(array_filter($tabLibs, static fn($l) => $l['status'] !== 'ok' && !($l['kind'] === 'external' && $l['status'] === 'missing')));
$tabPending = count(array_filter(NuMigrate::status(), static fn($x) => !$x['applied']));
$tabSchema = ($e = NuSchemaCheck::installed()) ? NuSchemaCheck::compare($e) : null;
$tabSchemaBad = $tabSchema && ($tabSchema['missing'] || $tabSchema['changed']);
$tabSys = !$reqOk ? 'danger' : ($tabPending || $tabSchemaBad ? 'warning' : '');
$lpView = NuLibs::overview();                                         // library packs (0.8.405)
$lpUpdates = count(array_filter($lpView, static fn($x) => $x['update'] && ($x['pack'] !== '' || $x['version'] !== '')));
$lpWarn = NuLibs::warnings();
$tabLib = $lpWarn || $tabLibProblems ? 'warning' : ($lpUpdates ? 'info' : '');
$tabHist = count(NuUpdate::history());
$tabs = ['update' => ['bi-cloud-arrow-down', t('upd_tab_update'), ''], 'backups' => ['bi-archive', t('upd_tab_backups'), $tabBackups ? '<span class="badge rounded-pill text-bg-light border ms-1">' . $tabBackups . '</span>' : ''],
	'history' => ['bi-clock-history', t('upd_tab_history'), $tabHist ? '<span class="badge rounded-pill text-bg-light border ms-1">' . $tabHist . '</span>' : ''],
	'libraries' => ['bi-boxes', t('upd_tab_libs'), $tabLib !== '' ? '<span class="nuTabDot bg-' . $tabLib . ' ms-1" aria-hidden="true"></span><span class="visually-hidden">' . h(t($tabLib === 'warning' ? 'lp_tab_warn' : 'lp_tab_updates')) . '</span>' : ''],
	'system' => ['bi-hdd-stack', t('upd_tab_system'), $tabSys !== '' ? '<span class="nuTabDot bg-' . $tabSys . ' ms-1" aria-hidden="true"></span><span class="visually-hidden">' . h(t($tabSys === 'danger' ? 'upd_tab_sys_problem' : 'upd_tab_sys_notice')) . '</span>' : '']]; ?>
<?php // ?tab=<name> (0.8.451): the server opens that tab itself – pager links and redirects carry it, so the page is not hidden until the
// script switched tabs (nutheme.js hides [data-nu-tab-restore] only when the address names another tab than ?tab=)
$updTab = (string)($_GET['tab'] ?? 'update');
if (!isset($tabs[$updTab])) $updTab = 'update'; ?>
<ul class="nav nav-tabs nuUpdTabs mb-4" id="updTabs" role="tablist" data-nu-hash-tabs data-nu-tab-restore>
	<?php foreach ($tabs as $k => [$icon, $label, $extra]): ?>
	<li class="nav-item" role="presentation"><button class="nav-link<?= $k === $updTab ? ' active' : '' ?>" id="updTabBtn-<?= $k ?>" data-bs-toggle="tab" data-bs-target="#updPane-<?= $k ?>" data-nu-tab="<?= $k ?>" type="button" role="tab" aria-controls="updPane-<?= $k ?>" aria-selected="<?= $k === $updTab ? 'true' : 'false' ?>"<?php if (preg_match('~visually-hidden">([^<]+)<~', $extra, $tm)): ?> title="<?= $tm[1] ?>"<?php endif ?>><i class="bi <?= $icon ?> me-1"></i><?= h($label) ?><?= $extra ?></button></li>
	<?php endforeach; ?>
</ul>
<div class="tab-content" id="updPanes" data-nu-tab-restore>
<div class="tab-pane fade<?= $updTab === 'update' ? ' show active' : '' ?>" id="updPane-update" role="tabpanel" aria-labelledby="updTabBtn-update" tabindex="0">
<?php ob_start(); if ($finished): ?>
	<div class="card shadow-sm mb-4 border-<?= $finished['ok'] ? 'success' : 'danger' ?>" id="updFinished">
		<div class="card-body">
			<h2 class="h5 <?= $finished['ok'] ? 'text-success' : 'text-danger' ?>"><i class="bi <?= $finished['ok'] ? 'bi-check-circle' : 'bi-x-circle' ?> me-2"></i><?= h(t($finished['ok'] ? 'upd_done' : 'upd_done_db_error', $finished['from'], $finished['to'])) ?></h2>
			<?php if (!$finished['ok']): ?><p class="text-danger small"><?= h($finished['error']) ?></p><?php endif; ?>
			<p class="small mb-1"><?= h(t('upd_done_backup', $finished['backup'])) ?></p>
			<?php if (!empty($finished['schema'])): ?><p class="small mb-1"><?= h(t('upd_schema_done', implode(', ', $finished['schema']))) ?></p><?php endif; ?>
			<?php if (!empty($finished['steps'])): ?><p class="small mb-1"><?= h(t('upd_done_steps', implode(', ', $finished['steps']))) ?></p><?php endif; ?>
			<?php if (!empty($finished['sample'])): ?><p class="small mb-1" id="updSampleDone"><?= h(t('upd_sample_done', (int)$finished['sample'])) ?> <a href="<?= h(nu_url('sample')) ?>"><?= h(t('upd_sample_passwords')) ?></a></p><?php endif; ?>
			<?php if (!empty($finished['sample_error'])): ?><p class="small mb-1 text-danger" id="updSampleFailed"><?= h(t('upd_sample_failed')) ?> <?= h($finished['sample_error']) ?></p><?php endif; ?>
			<?php $new = NuUpdate::changesSince((array)$finished['changes'], (string)$finished['from']); if ($new): ?>
				<h3 class="h6 mt-3"><?= h(t('upd_whats_new')) ?></h3><?= $changes($new) ?>
			<?php endif; ?>
		</div>
	</div>
<?php endif;
// developer mode (0.8.588 / 0.8.590): after an update the upload card comes first, the result of the update below it
$finishedHtml = (string)ob_get_clean();
$devUploadFirst = $finished && !$check && $updOn && !empty(nu_cfg('nuConfigDevMode'));
if (!$devUploadFirst) echo $finishedHtml; ?>

<?php if ($check): ?>
	<div class="card shadow-sm mb-4" id="updCheck">
		<div class="card-header fw-semibold"><i class="bi bi-box-seam me-2"></i><?= h(t('upd_package')) ?> <?= h($check['version']) ?></div>
		<div class="card-body">
			<?php if (!$check['ok']): ?>
				<div class="alert alert-danger mb-3"><b><?= h(t('upd_package_invalid')) ?></b><ul class="mb-0"><?php foreach ($check['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>
			<?php // install options, Install and Cancel right below the package title (0.8.534), the details of the check below them ?>
			<div class="d-flex flex-wrap gap-2 align-items-center pb-3 mb-3 border-bottom" id="updActions">
				<?php if ($check['ok'] && $reqOk && $updOn): ?>
					<form method="post" action="<?= h(nu_url('update')) ?>" class="nuContents" id="updInstallForm" data-nu-once data-nu-lock="#updCancel" data-nu-progress="#updProgress" data-confirm="<?= h(t('upd_install_confirm', $check['version'])) ?>" data-confirm-ok="<?= h(t('upd_install')) ?>" data-confirm-class="btn btn-primary">
						<?= nu_csrf_field() ?><input type="hidden" name="action" value="install"><input type="hidden" name="id" value="<?= h($check['id']) ?>">
						<?php if (!version_compare($check['version'], NU_VERSION, '>')): ?>
							<div class="form-check me-2"><input class="form-check-input" type="checkbox" id="reinstall" name="reinstall" value="1"><label class="form-check-label small" for="reinstall"><?= h(t('upd_reinstall')) ?></label></div>
						<?php endif; ?>
						<?php if (NuUpdate::loadDump() && NuSqlDump::available()):   // backup before the update (0.8.615): database part – nuBuilder's tables by default ?>
							<div class="d-flex flex-wrap align-items-center gap-2 me-2 updBkDbRow">
								<label class="small text-body-secondary text-nowrap" for="updBkDb"><?= h(t('upd_install_db')) ?></label>
								<select class="form-select form-select-sm updBkDbSel" id="updBkDb" name="backup_db">
									<option value="nubuilder" selected><?= h(t('upd_bk_db_nubuilder', NuFile::size(NuSqlDump::estimate('nubuilder', true)))) ?></option>
									<option value="all"><?= h(t('upd_bk_db_all', NuFile::size(NuSqlDump::estimate('all', true)))) ?></option>
									<option value="none"><?= h(t('upd_bk_db_none')) ?></option>
								</select>
								<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_install_db_hint')) ?>" title="<?= h(t('upd_install_db_hint')) ?>"><i class="bi bi-info-circle"></i></span>
							</div>
							<input type="hidden" name="backup_nologs" value="1">
						<?php endif; ?>
						<?php if ($hasPages): // page forms (0.8.387): the installation's page folders as a copy in the backup (off by default) ?>
							<div class="form-check me-2"><input class="form-check-input" type="checkbox" id="updBkPages" name="backup_pages" value="1">
								<label class="form-check-label small" for="updBkPages"><?= h(t('upd_bk_pages')) ?></label>
								<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_bk_pages_hint')) ?>" title="<?= h(t('upd_bk_pages_hint')) ?>"><i class="bi bi-info-circle"></i></span></div>
						<?php endif; ?>
						<?php if (!empty($check['sample'])): $sv = $check['sample']; $sNewer = $sv['to'] > $sv['from']; ?>
							<div class="form-check me-2"><input class="form-check-input" type="checkbox" id="updSample" name="sample_refresh" value="1"<?= $sNewer ? ' checked' : '' ?>>
								<label class="form-check-label small" for="updSample"><?= h($sNewer ? t('upd_sample_refresh', $sv['from'], $sv['to']) : t('upd_sample_refresh_same')) ?></label>
								<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_sample_refresh_tip')) ?>" title="<?= h(t('upd_sample_refresh_tip')) ?>"><i class="bi bi-info-circle"></i></span></div>
						<?php endif; ?>
						<div class="w-100" aria-hidden="true"></div><?php // Install and Cancel on their own row below the options (0.8.505) ?>
						<button class="btn btn-primary" type="submit" id="updInstall" data-nu-busy="<?= h(t('upd_installing')) ?>"><i class="bi bi-download me-1"></i><?= h(t('upd_install')) ?></button>
					</form>
				<?php endif; ?>
				<?php // shown while the install request runs (data-nu-progress): no close button, no Escape ?>
				<div class="modal fade" id="updProgress" tabindex="-1" aria-labelledby="updProgressTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
					<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
						<div class="modal-body p-4 text-center">
							<div class="spinner-border text-primary mb-3 nuUpdSpinner" role="status" aria-hidden="true"></div>
							<h2 class="h5 mb-2" id="updProgressTitle"><?= h(t('upd_progress_title', $check['version'])) ?></h2>
							<p class="mb-3 small text-body-secondary"><?= h(t('upd_progress_steps')) ?></p>
							<div class="alert alert-warning text-start small mb-2 py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('upd_progress_keep')) ?></div>
							<p class="small text-body-secondary text-start mb-0"><?= h(t('upd_progress_info')) ?></p>
						</div>
					</div></div>
				</div>
				<form method="post" action="<?= h(nu_url('update')) ?>">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="discard"><input type="hidden" name="id" value="<?= h($check['id']) ?>">
					<button class="btn btn-outline-secondary" type="submit" id="updCancel"><?= h(t('cancel')) ?></button>
				</form>
			</div>
			<?php if ($check['ok']):
				$d = $check['diff'];
				$newer = version_compare($check['version'], NU_VERSION, '>'); ?>
				<p class="mb-2"><?= h(t('upd_from_to', NU_VERSION, $check['version'])) ?><?= !empty($check['manifest']['built']) ? ' · ' . h(t('upd_built', (string)$check['manifest']['built'])) : '' ?></p>
				<?php $sigState = (string)($check['signature'] ?? 'unsigned'); // release signature (0.8.207): valid / unsigned / unchecked ?>
				<?php $ff = $_SESSION['nu_upd_folder'] ?? null; if (is_array($ff) && ($ff['id'] ?? '') === $check['id']): ?>
				<p class="small mb-1" id="updFromFolder"><i class="bi bi-folder2-open me-1"></i><?= h(t('upd_folder_from', (string)$ff['name'])) ?></p>
				<?php endif; ?>
				<p class="small mb-2" id="updSignature"><?php if ($sigState === 'valid'): ?><i class="bi bi-patch-check-fill text-success me-1"></i><?= h(t('upd_sig_valid')) ?>
					<?php else: ?><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i><?= h(t($sigState === 'unchecked' ? 'upd_sig_unchecked' : 'upd_sig_unsigned')) ?><?php endif; ?></p>
				<?php if (($check['manifest']['gated'] ?? null) === false): // fast release without the full release gate (0.8.389) ?>
					<div class="alert alert-warning small py-2 d-flex gap-2 align-items-start" id="updNotGated" role="alert"><i class="bi bi-cone-striped mt-1"></i><div><b><?= h(t('upd_not_gated')) ?></b> <?= h(t('upd_not_gated_hint')) ?></div></div>
				<?php endif; ?>
				<ul class="small mb-3">
					<li><?= h(t('upd_diff_changed', $d['changed'])) ?> · <?= h(t('upd_diff_new', $d['new'])) ?> · <?= h(t('upd_diff_same', $d['same'])) ?></li>
					<li><?= h(t('upd_diff_removed', count($d['removed']))) ?><?php if ($d['removed']): ?>
						<details class="d-inline ms-1"><summary class="text-body-secondary"><?= h(t('upd_show')) ?></summary><code class="d-block small"><?= h(implode("\n", $d['removed'])) ?></code></details><?php endif; ?></li>
					<li><?= h(t('upd_keeps')) ?></li>
					<?php if (isset($check['manifest']['libraries'])): $libChg = NuLibraries::changes((array)$check['manifest']); // 0.8.229 ?>
						<li id="updLibChanges"><?php if (!$libChg): ?><?= h(t('upd_libs_unchanged')) ?><?php else: ?><?= h(t('upd_libs_changed')) ?>
							<?= h(implode(' · ', array_map(static fn($c) => $c['name'] . ' ' . ($c['from'] === '' ? t('upd_libs_new') . ' ' . $c['to'] : ($c['to'] === '' ? t('upd_libs_dropped') : $c['from'] . ' → ' . $c['to'])), $libChg))) ?><?php endif; ?></li>
					<?php endif; ?>
				</ul>
				<?php $libReq = NuLibs::unmet(NuLibs::required((array)$check['manifest']), null, NuLibs::available()); // library packs the new version needs (0.8.406)
				if ($libReq): $libNeeded = (bool)array_filter($libReq, static fn($x) => $x['needed'] && $x['have'] === ''); $libCan = array_filter($libReq, static fn($x) => $x['best'] !== null); ?>
					<div class="alert <?= $libNeeded ? 'alert-danger' : 'alert-warning' ?> small py-2" id="updLibReq" role="alert">
						<div class="fw-semibold mb-1"><i class="bi bi-boxes me-1"></i><?= h(t('lp_req_title', $check['version'])) ?></div>
						<ul class="mb-2">
						<?php foreach ($libReq as $x): ?>
							<li data-slot="<?= h($x['slot']) ?>"><?= h(t('lp_req_line', $x['title'], $x['min'])) ?> · <?= h($x['have'] !== '' ? t('lp_req_have', $x['have']) : t('lp_req_none')) ?>
								· <?= $x['best'] ? h(t('lp_req_avail', $x['best']['version'], $x['best']['where'] === 'github' ? 'GitHub' : $x['best']['folder'] . '/')) : '<span class="fw-semibold">' . h(t('lp_req_noavail')) . '</span>' ?><?= $x['needed'] ? '' : ' · ' . h(t('lp_req_optional')) ?></li>
						<?php endforeach; ?>
						</ul>
						<div class="d-flex flex-wrap gap-2 align-items-center">
						<?php if ($libCan && NuUpdate::enabled()): ?>
							<form method="post" action="<?= h(nu_url('update')) ?>" class="m-0" data-nu-once><?= nu_csrf_field() ?><input type="hidden" name="action" value="lp_install"><input type="hidden" name="back_check" value="<?= h((string)$check['id']) ?>">
								<?php foreach ($libCan as $x): ?><input type="hidden" name="slots[]" value="<?= h($x['slot']) ?>"><?php endforeach; ?>
								<button class="btn btn-sm btn-primary" type="submit" id="updLibReqInstall" data-nu-busy="<?= h(t('lp_installing')) ?>"><i class="bi bi-download me-1"></i><?= h(t('lp_req_install', count($libCan))) ?></button></form>
						<?php endif; ?>
							<span><?= h(t('lp_req_hint')) ?></span>
						</div>
					</div>
				<?php endif; ?>
				<?php if (!$newer): ?><div class="alert alert-danger small py-2 d-flex gap-2 align-items-start" role="alert"><i class="bi bi-exclamation-octagon mt-1"></i><div><?= h(t('upd_not_newer', $check['version'], NU_VERSION)) ?></div></div><?php endif; ?>
				<?php $new = NuUpdate::changesSince((array)($check['manifest']['changes'] ?? []), NU_VERSION); if ($new): ?>
					<details class="mb-3" open><summary class="fw-semibold"><?= h(t('upd_whats_new')) ?></summary><?= $changes($new) ?></details>
				<?php endif; ?>
				<?php if (!$reqOk): // name the failed requirements here; the card "Server" further down has the whole list ?>
					<div class="alert alert-danger small py-2" id="updReqFailed"><?= h(t('upd_req_failed')) ?>
						<ul class="mb-1 mt-1"><?php foreach ($req as [$label, $ok, $detail]): if ($ok) continue; ?><li><strong><?= h($label) ?></strong>: <?= h($detail) ?></li><?php endforeach; ?></ul>
						<a class="alert-link" href="#system"><?= h(t('upd_req_see_card')) ?></a> · <?= h(t('upd_req_hint')) ?></div>
				<?php endif; ?>
				<?php if (isset($check['integrity'])): $icR = $check['integrity']; ?>
				<div class="border rounded p-3 mb-3<?= $icR['errors'] ? ' border-danger' : '' ?>" id="updIntegrity">
					<div class="fw-semibold small mb-2"><i class="bi bi-clipboard2-pulse me-1"></i><?= h(t('ic_title')) ?><?= NuListView::infoTip(t('ic_tip')) ?></div>
					<?= NuIntegrity::html($icR, 'updInstallForm') ?>
				</div>
				<?php endif; ?>
				<?php if (isset($check['dbc'])): ?>
				<div class="border rounded p-3 mb-3" id="updDbChanges">
					<div class="fw-semibold small mb-2"><i class="bi bi-database-exclamation me-1"></i><?= h(t('upd_dbc_title')) ?>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_dbc_tip')) ?>" title="<?= h(t('upd_dbc_tip')) ?>"><i class="bi bi-info-circle"></i></span></div>
					<?= $dbcList($check['dbc']) ?>
				</div>
				<?php endif; ?>
				<?php if (!empty($check['pages'])): $pgBad = array_values(array_filter($check['pages'], static fn($x) => $x['issues'])); ?>
				<div class="border rounded p-3 mb-3" id="updPages">
					<div class="fw-semibold small mb-2"><i class="bi bi-filetype-php me-1"></i><?= h(t('upd_pages_title')) ?>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_pages_tip')) ?>" title="<?= h(t('upd_pages_tip')) ?>"><i class="bi bi-info-circle"></i></span></div>
					<?php if (!$pgBad): ?>
						<p class="small text-success mb-0" id="updPagesOk"><i class="bi bi-check-circle me-1"></i><?= h(t('upd_pages_ok', count($check['pages']))) ?></p>
					<?php else: ?>
						<ul class="list-unstyled small mb-0" id="updPagesList">
						<?php foreach ($pgBad as $pg): $err = (bool)array_filter($pg['issues'], static fn($i) => $i['level'] === 'error'); ?>
							<li class="mb-2" data-page="<?= h($pg['code']) ?>"><span class="badge <?= $err ? 'bg-danger' : 'bg-warning text-dark' ?> me-1"><?= h(t($err ? 'upd_pages_breaks' : 'upd_pages_deprecated')) ?></span>
								<a href="<?= h(nu_url('builder', ['f' => $pg['id']])) ?>"><?= h($pg['description'] !== '' ? $pg['description'] : $pg['code']) ?></a> <code class="small"><?= h($pg['code']) ?></code>
								<ul class="mb-0"><?php foreach ($pg['issues'] as $i): ?><li class="<?= $i['level'] === 'error' ? 'text-danger' : 'text-warning-emphasis' ?>"><?= h($i['text']) ?></li><?php endforeach; ?></ul></li>
						<?php endforeach; ?>
						</ul>
						<p class="small text-body-secondary mt-1 mb-0"><?= h(t('upd_pages_hint')) ?></p>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<div class="border rounded p-3 mb-3" id="updSchemaCheck">
					<div class="fw-semibold small mb-2"><i class="bi bi-database-gear me-1"></i><?= h(t('upd_schema_title')) ?></div>
					<?= $schemaList($check['schema'] ?? null, 'updInstallForm') ?>
					<?php if (!empty($check['schema']['missing']) || !empty($check['schema']['changed'])): ?><p class="small text-body-secondary mt-2 mb-0"><?= h(t('upd_schema_backup_hint')) ?></p><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
<?php elseif ((!$finished || !empty(nu_cfg('nuConfigDevMode'))) && $updOn):   // developer mode: the upload card also right after an update, ABOVE its result (0.8.590) ?>
	<?php $folderPk = NuUpdate::folderPackages(); $folderReady = (bool)array_filter($folderPk, static fn($x) => $x['ok']); ?>
	<div class="card shadow-sm mb-4" id="updInstall">
		<div class="card-body">
			<h2 class="h5"><i class="bi bi-cloud-arrow-up me-2"></i><?= h(t('upd_upload_title')) ?></h2>
			<p class="small text-body-secondary"><?= h(t('upd_install_hint')) ?></p>
			<?php $gs = NuGitHub::settings(); $gl = $gs['latest']; $gNew = NuGitHub::newer(); $repoUrl = 'https://github.com/' . $gs['repo'] . '/releases'; ?>
			<div class="border rounded-3 p-3 p-sm-4 mb-3" id="updGitHub">
				<?php // collapsible (0.8.450): open by default, folded state remembered in this browser; a newer release keeps it open ?>
				<div class="d-flex flex-wrap align-items-center gap-2">
					<h3 class="h5 d-inline-flex align-items-center gap-1 mb-0"><button class="btn btn-link p-0 text-decoration-none text-reset fs-5 nuChevToggle d-inline-flex align-items-center"
						type="button" data-bs-toggle="collapse" data-bs-target="#updGitHubBody" aria-expanded="true" aria-controls="updGitHubBody" id="updGitHubToggle"><i class="bi bi-chevron-right me-1"></i><i class="bi bi-github me-2"></i><?= h(t('gh_title')) ?></button><?= NuListView::infoTip(t('gh_tip')) ?></h3>
					<button class="btn nuBtnAct ms-sm-2" type="button" data-bs-toggle="collapse" data-bs-target="#updGitHubSet" aria-expanded="false" aria-controls="updGitHubSet" id="updGitHubSetBtn" data-nu-open-first="#updGitHubBody"><i class="bi bi-gear me-1"></i><?= h(t('gh_settings')) ?></button>
				</div>
				<div class="collapse show" id="updGitHubBody" data-nu-remember-open="nunext-upd-github"<?= $gNew ? ' data-nu-force-open' : '' ?>><div class="pt-3">
				<?php if ($gs['error'] !== ''): ?><div class="alert alert-warning small py-2 mb-2" id="updGitHubError"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('gh_err_check', $gs['error'])) ?></div><?php endif; ?>
				<dl class="nuGhInfo<?= $gNew ? '' : ' mb-0' ?>" id="updGitHubInfo">
					<dt><?= h(t('gh_repo')) ?></dt>
					<dd><a href="<?= h($repoUrl) ?>" target="_blank" rel="noopener" id="updGitHubRepo"><?= h($gs['repo']) ?></a></dd>
					<dt><?= h(t('gh_lbl_checked')) ?></dt>
					<dd id="updGitHubChecked"><?= h($gs['checked_at'] ? date(nu_dfmt('d.m.Y H:i'), $gs['checked_at']) : t('gh_never')) ?><?= $gs['auto'] ? ' · ' . h(t('gh_auto_every', (static fn(string $x): string => mb_strtolower(mb_substr($x, 0, 1)) . mb_substr($x, 1))(t('gh_every_' . $gs['every'])))) : '' ?>
						<form method="post" action="<?= h(nu_url('update')) ?>" class="d-inline" data-nu-once><?= nu_csrf_field() ?><input type="hidden" name="action" value="github_check">
							<button class="btn btn-link p-0 ms-2 align-baseline nuGhAgain" type="submit" id="updGitHubCheck" data-nu-busy="<?= h(t('gh_checking')) ?>"><i class="bi bi-arrow-repeat me-1"></i><?= h(t('gh_check_again')) ?></button></form></dd>
					<?php if ($gl): $gCmp = version_compare((string)$gl['version'], NU_VERSION); ?>
					<dt><?= h(t('gh_lbl_latest')) ?></dt>
					<dd class="d-flex flex-wrap align-items-center gap-2" id="updGitHubLatest">
						<span class="fw-semibold"><?= h((string)$gl['version']) ?></span>
						<?php if (!empty($gl['pre'])): ?><span class="badge rounded-pill nuGhBadgePre"><?= h(t('gh_pre')) ?></span><?php endif; ?>
						<span class="badge rounded-pill <?= $gCmp > 0 ? 'text-bg-success' : 'nuGhBadgeMuted' ?>" id="updGitHubState"><?= h($gCmp > 0 ? t('upd_folder_newer') : ($gCmp === 0 ? t('gh_same') : t('gh_older'))) ?></span>
					</dd>
					<dt><?= h(t('gh_lbl_details')) ?></dt>
					<dd><?= $gl['published'] !== '' ? h(date(nu_dfmt('d.m.Y'), (int)strtotime((string)$gl['published']))) . ' · ' : '' ?><?= h(NuFile::size((int)$gl['size'])) ?>
						<?php if ($gl['page'] !== ''): ?> · <a href="<?= h((string)$gl['page']) ?>" target="_blank" rel="noopener"><?= h(t('gh_notes')) ?></a><?php endif; ?></dd>
					<?php endif; ?>
				</dl>
				<?php if ($gNew): ?>
				<form method="post" action="<?= h(nu_url('update')) ?>" data-nu-once><?= nu_csrf_field() ?><input type="hidden" name="action" value="github_download">
					<button class="btn btn-primary" type="submit" id="updGitHubDownload" data-nu-busy="<?= h(t('gh_downloading')) ?>"><i class="bi bi-cloud-download me-1"></i><?= h(t('gh_download', (string)$gNew['version'])) ?></button></form>
				<?php endif; ?>
				<div class="collapse" id="updGitHubSet">
					<form method="post" action="<?= h(nu_url('update')) ?>" class="border rounded p-3 mt-3 bg-body-tertiary" id="updGitHubSetForm"><?= nu_csrf_field() ?><input type="hidden" name="action" value="github_settings">
						<div class="row g-3 align-items-end">
							<div class="col-md-4"><label class="form-label small mb-1" for="ghRepo"><?= h(t('gh_repo')) ?></label><?= NuListView::infoTip(t('gh_repo_tip')) ?>
								<input type="text" class="form-control form-control-sm" name="repo" id="ghRepo" value="<?= h($gs['repo']) ?>" maxlength="140" required pattern="[A-Za-z0-9][A-Za-z0-9\-]*/[A-Za-z0-9._\-]+" spellcheck="false" autocomplete="off"></div>
							<div class="col-sm-6 col-md-3"><label class="form-label small mb-1" for="ghEvery"><?= h(t('gh_set_every')) ?></label>
								<select class="form-select form-select-sm" name="every" id="ghEvery"><?php foreach (array_keys(NuGitHub::EVERY) as $ev): ?>
									<option value="<?= h($ev) ?>"<?= $gs['every'] === $ev ? ' selected' : '' ?>><?= h(t('gh_every_' . $ev)) ?></option><?php endforeach; ?></select></div>
							<div class="col-sm-6 col-md-3">
								<div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" name="pre" value="1" id="ghPre"<?= $gs['pre'] ? ' checked' : '' ?>><label class="form-check-label small" for="ghPre"><?= h(t('gh_set_pre')) ?></label></div>
							</div>
							<div class="col-md-2 text-md-end"><button type="submit" class="btn btn-sm nuBtnAct nuBtnSave" id="ghSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button></div>
						</div>
					</form>
				</div>
				</div></div>
			</div>
			<button class="btn btn-link px-0 text-decoration-none nuChevToggle<?= $folderReady ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#updOther" aria-expanded="<?= $folderReady ? 'true' : 'false' ?>" aria-controls="updOther" id="updOtherBtn"><i class="bi bi-chevron-right me-1"></i><?= h(t('upd_other_title')) ?></button>
			<div class="collapse<?= $folderReady ? ' show' : '' ?>" id="updOther" data-nu-remember-open="nunext-upd-other"<?= $folderReady ? ' data-nu-force-open' : '' ?>>
				<div class="pt-3" id="updZip">
					<h3 class="h6 d-inline-flex align-items-center gap-1 mb-2"><i class="bi bi-file-earmark-zip me-1"></i><?= h(t('upd_zip_title')) ?></h3>
					<p class="small text-body-secondary"><?= h(t('upd_upload_hint')) ?></p>
			<form method="post" action="<?= h(nu_url('update')) ?>" enctype="multipart/form-data" class="d-flex flex-wrap gap-2" data-nu-once>
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="upload">
				<input class="form-control w-auto" type="file" name="package" id="updPackage" accept=".zip,application/zip" required aria-label="<?= h(t('upd_package')) ?>">
				<button class="btn btn-primary d-none" type="submit" id="updUpload" data-nu-needs-file="#updPackage" data-nu-busy="<?= h(t('upd_checking')) ?>"><i class="bi bi-search me-1"></i><?= h(t('upd_check')) ?></button>
				<?php if (NuHash::devMode()): // developer mode (0.8.733): check and install in one go ?>
				<button class="btn btn-outline-primary d-none" type="submit" name="quick" value="1" id="updQuick" data-nu-needs-file="#updPackage" data-nu-busy="<?= h(t('upd_quick_busy')) ?>"
					data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" title="<?= h(t('upd_quick_tip')) ?>"><i class="bi bi-lightning-charge me-1"></i><?= h(t('upd_quick')) ?></button>
				<?php endif; ?>
			</form>
				</div>
						<div class="mt-3 pt-3 border-top" id="updFolder">
				<h3 class="h6 d-inline-flex align-items-center gap-1 mb-2"><i class="bi bi-folder2-open me-1"></i><?= h(t('upd_folder_title')) ?><?= NuListView::infoTip(t('upd_folder_tip')) ?></h3>
				<?php if (!$folderPk): ?>
					<p class="small text-body-secondary mb-0" id="updFolderNone"><?= h(t('upd_folder_none')) ?></p>
				<?php else: $pre = null; foreach ($folderPk as $fp) if ($fp['ok'] && $pre === null) $pre = $fp['name']; ?>
					<form method="post" action="<?= h(nu_url('update')) ?>" class="d-flex flex-column gap-2 align-items-start" data-nu-once>
						<?= nu_csrf_field() ?><input type="hidden" name="action" value="folder_check">
						<div class="list-group w-100 nuUpdFolderList" role="radiogroup" aria-label="<?= h(t('upd_folder_title')) ?>">
						<?php foreach ($folderPk as $i => $fp):
							$state = !$fp['ok'] ? ($fp['version'] === '' ? t('upd_folder_notpkg') : t('upd_folder_sig_' . ($fp['signature'] === 'invalid' ? 'invalid' : 'unsigned')))
								: (version_compare($fp['version'], NU_VERSION, '>') ? t('upd_folder_newer') : t('upd_folder_reinstall')); ?>
							<label class="list-group-item d-flex align-items-center gap-2<?= $fp['ok'] ? '' : ' text-body-secondary' ?>" data-file="<?= h($fp['name']) ?>">
								<input class="form-check-input mt-0" type="radio" name="name" value="<?= h($fp['name']) ?>"<?= $fp['name'] === $pre ? ' checked' : '' ?><?= $fp['ok'] ? '' : ' disabled' ?>>
								<span class="flex-grow-1 text-break"><span class="fw-semibold"><?= h($fp['name']) ?></span>
									<?php if ($fp['version'] !== ''): ?> · <?= h($fp['version']) ?><?php endif; ?>
									<span class="d-block small text-body-secondary"><?= h(NuFile::size($fp['size'])) ?> · <?= h(date(nu_dfmt('d.m.Y H:i'), $fp['time'])) ?></span></span>
								<span class="badge <?= $fp['ok'] ? (version_compare($fp['version'], NU_VERSION, '>') ? 'text-bg-success' : 'text-bg-secondary') : 'text-bg-warning' ?>"><?= h($state) ?></span>
							</label>
						<?php endforeach; ?>
						</div>
						<?php if ($pre !== null): ?><button class="btn btn-primary" type="submit" id="updFolderCheck" data-nu-busy="<?= h(t('upd_checking')) ?>"><i class="bi bi-search me-1"></i><?= h(t('upd_check')) ?></button><?php endif; ?>
					</form>
				<?php endif; ?>
			</div>
			</div>
		</div>
	</div>
<?php if ($devUploadFirst) echo $finishedHtml; ?>
<?php endif; ?>

<?php if ($reqOk): ?>
	<p class="small text-success mb-0" id="updReqLine"><i class="bi bi-check-circle me-1"></i><?= h(t('upd_req_all_ok')) ?> · <a href="#system"><?= h(t('upd_req_details')) ?></a></p>
<?php elseif (!$check): ?>
	<div class="alert alert-danger small py-2" id="updReqLine"><?= h(t('upd_req_failed')) ?>
		<ul class="mb-1 mt-1"><?php foreach ($req as [$label, $ok, $detail]): if ($ok) continue; ?><li><strong><?= h($label) ?></strong>: <?= h($detail) ?></li><?php endforeach; ?></ul>
		<a class="alert-link" href="#system"><?= h(t('upd_req_details')) ?></a></div>
<?php endif; ?>
</div>
<div class="tab-pane fade<?= $updTab === 'backups' ? ' show active' : '' ?>" id="updPane-backups" role="tabpanel" aria-labelledby="updTabBtn-backups" tabindex="0">
		<div class="card shadow-sm" id="updBackupsCard">
			<?php // newest first, 10 per page with the list footer (first / previous / page x of y / next / last)
			$allBackups = NuUpdate::backups();
			$bkBytes = array_sum(array_column($allBackups, 'size'));
			$sqlOk = NuUpdate::loadDump() && NuSqlDump::available();
			// one-time token per page view (checked above); data-nu-once: the button is disabled after the first click (assets/js/nuapp.js)
			$bkOnce = bin2hex(random_bytes(12));
			$_SESSION['nu_backup_once'] = array_slice(($_SESSION['nu_backup_once'] ?? []) + [$bkOnce => time()], -20, null, true); ?>
			<div class="card-header">
				<div class="d-flex flex-wrap gap-2 align-items-baseline mb-2">
					<span class="fw-semibold me-auto"><?= h(t('upd_backups')) ?></span>
					<span class="small text-body-secondary" id="updBackupsTotal"><?= $allBackups ? h(t('upd_backups_total', count($allBackups), NuFile::size((int)$bkBytes))) : '' ?></span>
				</div>
				<form method="post" action="<?= h(nu_url('update')) ?>" class="d-flex flex-wrap gap-2 align-items-center" id="updBackupForm" data-nu-once>
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="backup"><input type="hidden" name="once" value="<?= h($bkOnce) ?>">
					<label class="small text-body-secondary" for="updBkScope"><?= h(t('upd_bk_db')) ?></label>
					<select class="form-select form-select-sm updBkDbSel" id="updBkScope" name="sql"<?= $sqlOk ? '' : ' disabled title="' . h(t('upd_bk_db_mysql')) . '"' ?>>
						<option value="none" selected><?= h(t('upd_bk_db_none')) ?></option>
						<?php if ($sqlOk): ?>
							<option value="nubuilder"><?= h(t('upd_bk_db_nubuilder', NuFile::size(NuSqlDump::estimate('nubuilder', true)))) ?></option>
							<option value="all"><?= h(t('upd_bk_db_all', NuFile::size(NuSqlDump::estimate('all', true)))) ?></option>
						<?php endif; ?>
					</select>
					<?php if ($sqlOk): ?>
						<div class="form-check mb-0 small"><input class="form-check-input" type="checkbox" id="updBkNologs" name="nologs" value="1" checked>
							<label class="form-check-label" for="updBkNologs"><?= h(t('upd_bk_nologs')) ?></label>
							<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_bk_nologs_hint', implode(', ', NuSqlDump::LOG_TABLES))) ?>" title="<?= h(t('upd_bk_nologs_hint', implode(', ', NuSqlDump::LOG_TABLES))) ?>"><i class="bi bi-info-circle"></i></span></div>
					<?php endif; ?>
					<?php if ($hasPages): ?>
						<div class="form-check mb-0 small"><input class="form-check-input" type="checkbox" id="updBkPages2" name="pages" value="1">
							<label class="form-check-label" for="updBkPages2"><?= h(t('upd_bk_pages')) ?></label>
							<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_bk_pages_hint')) ?>" title="<?= h(t('upd_bk_pages_hint')) ?>"><i class="bi bi-info-circle"></i></span></div>
					<?php endif; ?>
					<button class="btn btn-sm btn-outline-secondary ms-auto" type="submit" data-nu-busy="<?= h(t('upd_backup_running')) ?>"><i class="bi bi-archive me-1"></i><?= h(t('upd_backup_now')) ?></button>
				</form>
			</div>
			<?php
			$bkPer   = 10;
			$bkTotal = count($allBackups);
			$bkPage  = max(1, min((int)($_GET['pg'] ?? 1), (int)max(1, ceil($bkTotal / $bkPer))));
			$backups = array_slice($allBackups, ($bkPage - 1) * $bkPer, $bkPer);
			$bkProt = NuUpdate::protectedBackups($allBackups);
			$bkRule = NuUpdate::cleanupRule();
			// several at once and the clean-up rule (0.8.451, assets/js/nubackups.js): the rows' checkboxes belong to #updBkBulk ?>
			<form method="post" action="<?= h(nu_url('update')) ?>" id="updBkBulk" data-confirm="" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger"
				data-confirm-text="<?= h(t('upd_bk_del_confirm')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="delete_backups"><input type="hidden" name="pg" value="<?= $bkPage ?>"></form>
			<?php if ($allBackups): ?>
			<div class="d-flex flex-wrap align-items-center gap-2 px-3 py-2 border-bottom updBkTools">
				<button class="btn btn-sm btn-outline-danger" type="submit" form="updBkBulk" id="updBkDelSel" disabled data-label="<?= h(t('upd_bk_del_selected')) ?>"><i class="bi bi-trash me-1"></i><span><?= h(sprintf(t('upd_bk_del_selected'), 0)) ?></span></button>
				<span class="small text-body-secondary" id="updBkSelInfo" aria-live="polite"></span>
				<button class="btn btn-sm btn-outline-secondary ms-auto<?= $bkRule['auto'] ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#updBkClean" aria-expanded="false" aria-controls="updBkClean" id="updBkCleanBtn">
					<i class="bi bi-funnel me-1"></i><?= h(t('upd_bk_clean')) ?><?php if ($bkRule['auto']): ?> <span class="badge text-bg-secondary ms-1"><?= h(t('upd_bk_clean_auto_on')) ?></span><?php endif; ?></button>
			</div>
			<div class="collapse" id="updBkClean">
				<form method="post" action="<?= h(nu_url('update')) ?>" class="px-3 py-3 border-bottom bg-body-tertiary" id="updBkCleanForm"
					data-confirm="" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger" data-confirm-text="<?= h(t('upd_bk_del_confirm')) ?>"
					data-backups="<?= h(json_encode(array_map(static fn($b) => ['n' => $b['name'], 'v' => $b['version'], 't' => $b['created'] !== '' ? (int)strtotime($b['created']) : 0, 's' => $b['size'], 'p' => isset($bkProt[$b['name']])], $allBackups))) ?>"
					data-now="<?= time() ?>">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="backup_cleanup"><input type="hidden" name="run" value="0" id="updBkCleanRunFlag">
					<div class="d-flex flex-column gap-2 small">
						<div class="d-flex flex-wrap align-items-center gap-2">
							<input class="form-check-input mt-0" type="radio" name="mode" value="keep" id="updBkModeKeep"<?= $bkRule['mode'] === 'keep' ? ' checked' : '' ?>>
							<label for="updBkModeKeep"><?= h(t('upd_bk_clean_keep')) ?></label>
							<input type="number" class="form-control form-control-sm updKeepNum" name="keep_n" min="1" max="9999" value="<?= $bkRule['mode'] === 'keep' ? $bkRule['n'] : 10 ?>" aria-label="<?= h(t('upd_bk_clean_keep')) ?>" id="updBkKeepN">
							<span><?= h(t('upd_bk_clean_keep_suffix')) ?></span>
						</div>
						<div class="d-flex flex-wrap align-items-center gap-2">
							<input class="form-check-input mt-0" type="radio" name="mode" value="age" id="updBkModeAge"<?= $bkRule['mode'] === 'age' ? ' checked' : '' ?>>
							<label for="updBkModeAge"><?= h(t('upd_bk_clean_age')) ?></label>
							<input type="number" class="form-control form-control-sm updKeepNum" name="days" min="1" max="9999" value="<?= $bkRule['mode'] === 'age' ? $bkRule['n'] : 90 ?>" aria-label="<?= h(t('upd_bk_clean_age')) ?>" id="updBkDays">
							<span><?= h(t('upd_bk_clean_age_suffix')) ?></span>
						</div>
						<div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="auto" value="1" id="updBkAuto"<?= $bkRule['auto'] ? ' checked' : '' ?>>
							<label class="form-check-label" for="updBkAuto"><?= h(t('upd_bk_clean_auto')) ?></label></div>
						<div class="text-body-secondary" id="updBkCleanPreview" aria-live="polite" data-none="<?= h(t('upd_bk_clean_nothing')) ?>" data-some="<?= h(t('upd_bk_clean_preview')) ?>"
							data-kept="<?= h(t('upd_bk_clean_kept')) ?>"></div>
						<div class="d-flex flex-wrap gap-2">
							<button class="btn btn-sm btn-danger" type="submit" id="updBkCleanRun" data-label="<?= h(t('upd_bk_clean_run')) ?>"><i class="bi bi-trash me-1"></i><span><?= h(sprintf(t('upd_bk_clean_run'), 0)) ?></span></button>
							<button class="btn btn-sm btn-outline-secondary" type="submit" data-no-confirm id="updBkCleanSave"><i class="bi bi-floppy me-1"></i><?= h(t('upd_bk_clean_save')) ?></button>
						</div>
						<div class="text-body-secondary"><i class="bi bi-shield-check me-1"></i><?= h(t('upd_bk_prot_hint')) ?></div>
					</div>
				</form>
			</div>
			<?php endif; ?>
			<div class="table-responsive">
				<table class="table table-sm align-middle mb-0 nu-table" id="updBackups">
					<thead><tr><th class="updBkSelCol"><input class="form-check-input" type="checkbox" id="updBkAll" aria-label="<?= h(t('upd_bk_select_page')) ?>" title="<?= h(t('upd_bk_select_page')) ?>"></th><th><?= h(t('upd_version')) ?></th><th><?= h(t('upd_created')) ?></th><th><?= h(t('upd_reason')) ?></th><th class="text-end"><?= h(t('upd_size')) ?></th><th></th></tr></thead>
					<tbody>
					<?php if (!$backups): ?><tr><td colspan="6" class="text-center text-body-secondary py-4"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
					<?php foreach ($backups as $b): ?>
						<tr>
							<td class="updBkSelCol"><?php if (isset($bkProt[$b['name']])): ?><i class="bi bi-lock text-body-secondary" role="img" title="<?= h(t($bkProt[$b['name']])) ?>" aria-label="<?= h(t($bkProt[$b['name']])) ?>"></i><?php else: ?>
								<input class="form-check-input updBkSel" type="checkbox" form="updBkBulk" name="names[]" value="<?= h($b['name']) ?>" data-size="<?= (int)$b['size'] ?>" aria-label="<?= h(t('upd_bk_select', $b['version'])) ?>"><?php endif; ?></td>
							<td class="fw-semibold"><?= h($b['version']) ?></td>
							<td class="small text-nowrap"><?= $b['created'] ? h(date(nu_dfmt('d.m.Y H:i'), strtotime($b['created']))) : '' ?></td>
							<td class="small"><?= h($b['reason']) ?><?= $b['by'] !== '' ? ' · ' . h($b['by']) : '' ?>
								<?php $sq = $b['sql']; if ($sq): // database part: complete / incomplete / removed by the keep rule ?>
									<?php if (!empty($sq['removed'])): ?>
										<span class="badge ms-2 text-bg-light border text-body-secondary" title="<?= h(t('upd_sql_removed_hint')) ?>"><i class="bi bi-database-dash me-1"></i><?= h(t('upd_sql_removed')) ?></span>
									<?php elseif (!empty($sq['complete'])): ?>
										<span class="badge ms-2 text-bg-success" title="<?= h(t('upd_sql_badge_hint', t('upd_bk_scope_' . ($sq['scope'] ?? 'all')), (int)($sq['tables'] ?? 0), number_format((int)($sq['rows'] ?? 0), 0, '.', "'"))) ?>"><i class="bi bi-database me-1"></i>SQL <?= h(NuFile::size((int)($sq['bytes'] ?? 0))) ?></span>
									<?php else: ?>
										<span class="badge ms-2 text-bg-danger" title="<?= h((string)($sq['error'] ?? '')) ?>"><i class="bi bi-database-exclamation me-1"></i><?= h(t('upd_sql_badge_incomplete')) ?></span>
									<?php endif; ?>
								<?php endif; ?></td>
							<td class="small text-end text-nowrap"><?= h(NuFile::size($b['size'])) ?></td>
							<td class="text-end text-nowrap"><div class="updBkActions">   <?php /* download · roll back · delete as one row of equal buttons (0.8.259) */ ?>
								<?php if ($b['sql'] && empty($b['sql']['removed'])): // whole backup or only the SQL dump ?>
									<div class="dropdown d-inline-block">
										<button class="btn btn-sm btn-outline-secondary nuRowBtn updBkDl dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" title="<?= h(t('upd_download')) ?>" aria-label="<?= h(t('upd_download')) ?>"><i class="bi bi-download"></i></button>
										<ul class="dropdown-menu dropdown-menu-end">
											<li><a class="dropdown-item small" href="<?= h(nu_url('update', ['download' => $b['name']])) ?>"><i class="bi bi-file-zip me-2"></i><?= h(t('upd_download_zip')) ?></a></li>
											<li><a class="dropdown-item small" href="<?= h(nu_url('update', ['download' => $b['name'], 'part' => 'sql'])) ?>"><i class="bi bi-database-down me-2"></i><?= h(t('upd_download_sql')) ?></a></li>
										</ul>
									</div>
								<?php else: ?>
									<a class="btn btn-sm btn-outline-secondary nuRowBtn" href="<?= h(nu_url('update', ['download' => $b['name']])) ?>" title="<?= h(t('upd_download')) ?>" aria-label="<?= h(t('upd_download')) ?>"><i class="bi bi-download"></i></a>
								<?php endif; ?>
								<?php if ($updOn): ?>
								<form method="post" action="<?= h(nu_url('update')) ?>" class="d-inline" data-confirm="<?= h(t('upd_rollback_confirm', $b['version'])) ?>" data-confirm-ok="<?= h(t('upd_rollback')) ?>" data-confirm-class="btn btn-warning">
									<?= nu_csrf_field() ?><input type="hidden" name="action" value="rollback"><input type="hidden" name="name" value="<?= h($b['name']) ?>">
									<button class="btn btn-sm updRollback" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('upd_rollback')) ?></button>
								</form>
								<?php endif; ?>
								<form method="post" action="<?= h(nu_url('update')) ?>" class="d-inline" data-confirm="<?= h(t('upd_delete_confirm')) ?>" data-confirm-ok="<?= h(t('delete')) ?>" data-confirm-class="btn btn-danger">
									<?= nu_csrf_field() ?><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="name" value="<?= h($b['name']) ?>"><input type="hidden" name="pg" value="<?= $bkPage ?>">
									<button class="btn btn-sm btn-outline-danger nuRowBtn" type="submit" title="<?= h(t('delete')) ?>" aria-label="<?= h(t('delete')) ?>"><i class="bi bi-trash"></i></button>
								</form>
							</div></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ($bkTotal > $bkPer) nu_list_footer($bkTotal, $bkPage, $bkPer, 'update', array_filter(['hpg' => (int)($_GET['hpg'] ?? 0) > 1 ? (int)$_GET['hpg'] : null, 'hq' => trim((string)($_GET['hq'] ?? '')) ?: null, 'tab' => 'backups']), 'card-footer updBackupsNav', 'pg', '#backups'); ?>
			<?php if ($sqlOk): // keep rule: only the newest N backups keep their SQL dump ?>
				<form method="post" action="<?= h(nu_url('update')) ?>" class="card-footer d-flex flex-wrap gap-2 align-items-center small" id="updKeepForm">
					<?= nu_csrf_field() ?><input type="hidden" name="action" value="backup_keep">
					<label for="updKeep"><?= h(t('upd_keep_label')) ?></label>
					<input type="number" class="form-control form-control-sm updKeepNum" id="updKeep" name="keep" min="0" max="999" value="<?= NuUpdate::keepSql() ?>">
					<span><?= h(t('upd_keep_suffix')) ?></span>
					<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_keep_hint')) ?>" title="<?= h(t('upd_keep_hint')) ?>"><i class="bi bi-info-circle"></i></span>
					<button class="btn btn-sm btn-outline-secondary" type="submit"><?= h(t('save')) ?></button>
				</form>
			<?php endif; ?>
			<div class="card-footer small text-body-secondary"><?= h(t('upd_backups_hint', $dir)) ?></div>
		</div>
	
</div>
<div class="tab-pane fade<?= $updTab === 'history' ? ' show active' : '' ?>" id="updPane-history" role="tabpanel" aria-labelledby="updTabBtn-history" tabindex="0">
<?php $history = NuUpdate::history(); if ($history):
	// change history: 10 per page (?hpg=), search in version, title and text (?hq=) – the backup list keeps its page (?pg=) and vice versa
	$hq = trim((string)($_GET['hq'] ?? ''));
	$hits = $hq === '' ? $history : array_values(array_filter($history, static fn($c) => mb_stripos($c['version'] . ' ' . ($c['title'] ?? '') . ' ' . ($c['text'] ?? ''), $hq) !== false));
	$hPer = 10;
	$hTotal = count($hits);
	$hPage = max(1, min((int)($_GET['hpg'] ?? 1), (int)max(1, ceil($hTotal / $hPer))));
	$hList = array_slice($hits, ($hPage - 1) * $hPer, $hPer);
	$bkKeep = isset($_GET['pg']) && (int)$_GET['pg'] > 1 ? ['pg' => (int)$_GET['pg']] : []; ?>
<div class="card shadow-sm" id="updHistory">
	<form class="card-header d-flex flex-wrap gap-2 align-items-center" method="get" action="<?= h(nu_url('update')) ?>#history" role="search"><input type="hidden" name="tab" value="history">
		<input type="hidden" name="p" value="update"><?php foreach ($bkKeep as $k => $val): ?><input type="hidden" name="<?= h($k) ?>" value="<?= (int)$val ?>"><?php endforeach; ?>
		<span class="fw-semibold me-auto"><i class="bi bi-clock-history me-2"></i><?= h(t('upd_history')) ?> <span class="badge text-bg-secondary"><?= count($history) ?></span></span>
		<input type="search" name="hq" value="<?= h($hq) ?>" class="form-control form-control-sm w-auto" id="updHistorySearch" placeholder="<?= h(t('search')) ?>" aria-label="<?= h(t('upd_history_search')) ?>">
		<button type="button" class="btn btn-sm btn-outline-secondary" id="updHistoryAll" data-open="<?= h(t('upd_history_open_all')) ?>" data-close="<?= h(t('upd_history_close_all')) ?>"><?= h(t('upd_history_open_all')) ?></button>
	</form>
	<div class="list-group list-group-flush" id="updHistoryList">
		<?php foreach ($hList as $i => $c): $v = (string)$c['version']; $title = trim((string)($c['title'] ?? '')); $open = $hq !== '' || ($hPage === 1 && $i < 5); ?>
			<details class="list-group-item updHistoryItem"<?= $open ? ' open' : '' ?>>
				<summary class="d-flex flex-wrap gap-2 align-items-baseline"><span class="badge <?= $v === NU_VERSION ? 'text-bg-success' : 'text-bg-light border' ?>"><?= h($v) ?></span>
					<span class="fw-semibold"><?= h($title !== '' ? $title : t('upd_history_version', $v)) ?></span>
					<?php if ($v === NU_VERSION): ?><span class="small text-success"><?= h(t('upd_history_installed')) ?></span><?php endif; ?></summary>
				<div class="small mt-2 updHistoryText"><?= NuUpdate::changeHtml((string)($c['text'] ?? '')) ?></div>
			</details>
		<?php endforeach; ?>
		<?php if (!$hList): ?><div class="list-group-item text-body-secondary small"><?= h(t('no_records')) ?></div><?php endif; ?>
	</div>
	<?php if ($hTotal > $hPer) nu_list_footer($hTotal, $hPage, $hPer, 'update', ($hq !== '' ? ['hq' => $hq] : []) + $bkKeep + ['tab' => 'history'], 'card-footer', 'hpg', '#history'); ?>
</div>
<?php endif; ?>
<?php if (!$history): ?><p class="text-body-secondary small" id="updHistoryNone"><?= h(t('no_records')) ?></p><?php endif; ?>
</div>
<div class="tab-pane fade<?= $updTab === 'libraries' ? ' show active' : '' ?>" id="updPane-libraries" role="tabpanel" aria-labelledby="updTabBtn-libraries" tabindex="0">
<?php // library packs (0.8.405, core/nulibs.php)
$lpGh = NuLibs::ghState();
$lpTests = $_SESSION['nu_lp_test'] ?? [];
unset($_SESSION['nu_lp_test']);
$lpCan = NuUpdate::enabled();
$lpFrom = static fn(array $x): string => match ($x['from']) { 'pack' => t('lp_from_pack'), 'nubuilder' => t('lp_from_nubuilder'), 'manual' => t('lp_from_manual'), default => '' };
$lpTip = static fn(string $tip, string $icon = 'bi-info-circle'): string => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide nuTipPre" aria-label="' . h($tip) . '" title="' . h($tip) . '"><i class="bi ' . $icon . '"></i></span>';
?>
<div class="card shadow-sm mb-4" id="updLibPacks">
	<div class="card-header d-flex flex-wrap align-items-center gap-2"><span class="fw-semibold me-auto"><i class="bi bi-boxes me-2"></i><?= h(t('lp_title')) ?>
		<?php if ($lpUpdates): ?><span class="badge text-bg-info ms-1" id="lpBadgeUpdates"><?= h(t('lp_badge_updates', $lpUpdates)) ?></span><?php endif; ?>
		<?php if ($lpWarn): ?><span class="badge text-bg-warning ms-1" id="lpBadgeWarn"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('lp_badge_warn', $lpWarn)) ?></span><?php endif; ?>
		<?= $lpTip(t('lp_hint')) ?></span>
		<form method="post" action="<?= h(nu_url('update')) ?>" data-nu-once class="m-0"><?= nu_csrf_field() ?><input type="hidden" name="action" value="lp_gh_check">
			<button class="btn btn-sm btn-outline-secondary" type="submit" id="lpGhCheck" data-nu-busy="<?= h(t('gh_checking')) ?>"><i class="bi bi-github me-1"></i><?= h(t('lp_gh_check')) ?></button></form>
		<?php if (NuHash::devMode() && !empty(NuAuth::user()['globeadmin'])): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(nu_url('libpack')) ?>" id="lpBuildLink"><i class="bi bi-pen me-1"></i><?= h(t('lp_build_title')) ?> <span class="badge text-bg-secondary">Dev</span></a><?php endif; ?>
	</div>
	<div class="card-body py-2 small text-body-secondary" id="lpGhState">
		<?php if ($lpGh['checked_at']): ?>
			<?= $lpGh['error'] !== '' ? '<i class="bi bi-exclamation-triangle text-warning me-1"></i>' . h(t('lp_gh_error', $lpGh['error'])) : h(t('lp_gh_state', $lpGh['repo'], date(nu_dfmt('d.m.Y H:i'), (int)$lpGh['checked_at']), count($lpGh['libs']))) ?>
		<?php else: ?><?= h(t('lp_gh_never')) ?><?php endif; ?>
		· <?= h(t('lp_folder_hint', '_update/')) ?>
	</div>
	<div class="table-responsive">
		<table class="table table-sm nu-table mb-0 small align-middle" id="lpTable">
			<thead><tr><th class="nuLpCheck"><input class="form-check-input" type="checkbox" id="lpAll" aria-label="<?= h(t('lp_select_all')) ?>"></th><th><?= h(t('upd_libs_name')) ?></th><th class="d-none d-md-table-cell"><?= h(t('lp_col_inuse')) ?></th><th class="d-none d-md-table-cell"><?= h(t('lp_col_available')) ?></th><th class="d-none d-md-table-cell"><?= h(t('upd_libs_state')) ?></th><th class="text-end"><span class="visually-hidden"><?= h(t('lp_col_actions')) ?></span></th></tr></thead>
			<tbody>
			<?php foreach ($lpView as $sl => $x):
				$best = $x['best'];
				[$icon, $text] = $x['unmet'] ? ['bi-exclamation-triangle text-warning', t('lp_req_needs', (string)$x['req']['min'])] : match ($x['status']) {
					'ok' => ['bi-check-circle text-success', t('upd_libs_ok') . ($x['note'] !== '' ? ' – ' . t($x['note']) : '')],
					'missing' => ['bi-dash-circle text-body-secondary', t('lp_missing')],
					'old' => ['bi-exclamation-triangle text-warning', t('lp_below_safe', $x['min'])],
					default => ['bi-exclamation-triangle text-warning', $x['note'] !== '' ? t($x['note']) : t('upd_libs_ok')],
				};
				$test = $lpTests[$sl] ?? null; ?>
				<tr data-slot="<?= h($sl) ?>" class="nuLpRow">
					<td class="nuLpCheck"><input class="form-check-input" type="checkbox" form="lpForm" name="slots[]" value="<?= h($sl) ?>" id="lpSel-<?= h($sl) ?>" aria-label="<?= h($x['title']) ?>"></td>
					<td><label class="fw-semibold" for="lpSel-<?= h($sl) ?>"><?= h($x['title']) ?></label><div class="text-body-secondary"><?= h(t('upd_libuse_' . $x['use'])) ?></div>
						<div class="d-md-none nuLpCompact"><i class="bi <?= $icon ?> me-1"></i><?= h(($x['pack'] !== '' ? $x['pack'] : ($x['version'] !== '' ? $x['version'] : $text)) . ($best && $x['update'] ? ' → ' . $best['version'] : '')) ?></div>
						<?php if ($test): ?><div class="d-md-none small"><?= h($test[1]) ?></div><?php endif; ?></td>
					<td class="nuLpInUse d-none d-md-table-cell"><?php if ($x['version'] !== '' || $x['pack'] !== ''): ?>
						<span class="fw-semibold"><?= h($x['pack'] !== '' ? $x['pack'] : $x['version']) ?></span>
						<span class="badge text-bg-light border ms-1 nuLpFrom"><?= h($lpFrom($x)) ?></span><?= $x['path'] !== '' ? $lpTip(str_replace(NU_ROOT . '/', '', $x['path']), 'bi-folder2') : '' ?>
						<?php if ($x['prev'] !== ''): ?><div class="text-body-secondary"><?= h(t('lp_prev', $x['prev'])) ?></div><?php endif; ?>
					<?php else: ?><span class="text-body-secondary">–</span><?php endif; ?></td>
					<td class="nuLpAvail d-none d-md-table-cell"><?php if ($best): ?>
						<span class="<?= $x['update'] ? 'fw-semibold text-primary-emphasis' : '' ?>"><?= h($best['version']) ?></span>
						<span class="text-body-secondary ms-1"><i class="bi <?= $best['where'] === 'github' ? 'bi-github' : 'bi-folder2' ?> me-1"></i><?= h($best['where'] === 'github' ? 'GitHub' : $best['folder'] . '/') ?></span>
					<?php else: ?><span class="text-body-secondary">–</span><?php endif; ?>
					<?php if ($x['rejected']): $rt = implode("\n", array_map(static fn($c) => basename((string)($c['file'] ?? '')) . ': ' . implode(' ', $c['errors']), $x['rejected'])); ?>
						<div class="text-danger nuLpRejected"><i class="bi bi-shield-x me-1"></i><?= h(t('lp_rejected', count($x['rejected']))) ?><?= $lpTip($rt) ?></div><?php endif; ?></td>
					<td class="nuLibState d-none d-md-table-cell"><i class="bi <?= $icon ?> me-1"></i><?= h($text) ?>
						<?php if ($test): ?><div class="nuLpTest nuLpTest-<?= h($test[0]) ?>"><i class="bi <?= ['ok' => 'bi-check2-circle text-success', 'warn' => 'bi-exclamation-circle text-warning', 'fail' => 'bi-x-octagon text-danger'][$test[0]] ?? 'bi-question-circle' ?> me-1"></i><?= h($test[1]) ?></div><?php endif; ?></td>
					<td class="text-end text-nowrap">
						<?php if ($best && $x['update'] && $lpCan): ?><button class="btn btn-sm btn-primary nuLpInstall" type="submit" form="lpF-lp_install-<?= h($sl) ?>" data-nu-busy="<?= h(t('lp_installing')) ?>" <?php // the same version from an old place (no pack yet): "Use pack", not "Update" (review D9)
							$lpLbl = $x['pack'] === '' && $x['version'] !== '' && !NuLibs::newer((string)$best['version'], (string)$x['version']) ? 'lp_use_pack' : ($x['pack'] !== '' || $x['version'] !== '' ? 'lp_update' : 'lp_install'); ?>
							aria-label="<?= h(t($lpLbl)) ?>"<?= $lpLbl === 'lp_use_pack' ? ' title="' . h(t('lp_use_pack_tip')) . '"' : '' ?>><i class="bi bi-download"></i><span class="d-none d-sm-inline ms-1"><?= h(t($lpLbl)) ?></span></button><?php endif; ?>
						<div class="dropdown d-inline-block">
							<button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('lp_col_actions')) ?>" title="<?= h(t('lp_col_actions')) ?>"><i class="bi bi-three-dots-vertical"></i></button>
							<ul class="dropdown-menu dropdown-menu-end">
								<?php if ($best && !$x['update'] && $lpCan): ?><li><button class="dropdown-item" type="submit" form="lpF-lp_install-<?= h($sl) ?>"><i class="bi bi-arrow-repeat me-2"></i><?= h(t('lp_reinstall', $best['version'])) ?></button></li><?php endif; ?>
								<li><button class="dropdown-item" type="submit" form="lpF-lp_test-<?= h($sl) ?>"><i class="bi bi-clipboard2-pulse me-2"></i><?= h(t('lp_test')) ?></button></li>
								<?php if ($x['prev'] !== '' && $lpCan): ?><li><button class="dropdown-item" type="submit" form="lpF-lp_rollback-<?= h($sl) ?>"><i class="bi bi-arrow-counterclockwise me-2"></i><?= h(t('lp_rollback', $x['prev'])) ?></button></li><?php endif; ?>
								<?php if ($x['installed'] && $lpCan): ?><li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger" type="submit" form="lpF-lp_remove-<?= h($sl) ?>"><i class="bi bi-trash me-2"></i><?= h(t('lp_remove')) ?></button></li><?php endif; ?>
							</ul>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php foreach ($lpView as $sl => $x): foreach (['lp_install', 'lp_test', 'lp_rollback', 'lp_remove'] as $a):
		$cf = $a === 'lp_rollback' ? t('lp_rollback_confirm', $x['title'], $x['prev']) : ($a === 'lp_remove' ? t('lp_remove_confirm', $x['title']) : ''); ?>
		<form method="post" action="<?= h(nu_url('update')) ?>" id="lpF-<?= $a ?>-<?= h($sl) ?>" class="d-none" data-nu-once<?= $cf !== '' ? ' data-confirm="' . h($cf) . '"' . ($a === 'lp_remove' ? ' data-confirm-ok="' . h(t('lp_remove')) . '" data-confirm-class="btn btn-danger"' : '') : '' ?>><?= nu_csrf_field() ?><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="slot" value="<?= h($sl) ?>"></form>
	<?php endforeach; endforeach; ?>
	<form method="post" action="<?= h(nu_url('update')) ?>" id="lpForm" class="card-footer d-flex flex-wrap align-items-center gap-2" data-nu-once><?= nu_csrf_field() ?>
		<?php if ($lpCan): ?><button class="btn btn-sm btn-primary" type="submit" name="action" value="lp_install" id="lpInstallSel" data-lp-bulk disabled data-nu-busy="<?= h(t('lp_installing')) ?>"><i class="bi bi-download me-1"></i><?= h(t('lp_install_sel')) ?></button><?php endif; ?>
		<button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="lp_test" id="lpTestSel" data-lp-bulk disabled><i class="bi bi-clipboard2-pulse me-1"></i><?= h(t('lp_test_sel')) ?></button>
		<span class="small text-body-secondary ms-auto"><?= h(t('lp_keep_hint', NuLibs::KEEP)) ?></span>
	</form>
	<?php if ($lpCan): ?>
	<div class="card-footer">
		<form method="post" action="<?= h(nu_url('update')) ?>" enctype="multipart/form-data" class="d-flex flex-wrap align-items-center gap-2" id="lpUploadForm" data-nu-once><?= nu_csrf_field() ?><input type="hidden" name="action" value="lp_upload">
			<label class="small fw-semibold" for="lpUpload"><?= h(t('lp_upload')) ?></label><?= $lpTip(t('lp_upload_hint')) ?>
			<input class="form-control form-control-sm w-auto" type="file" name="pack" id="lpUpload" accept=".zip" required>
			<button class="btn btn-sm btn-outline-primary" type="submit" id="lpUploadBtn" data-nu-busy="<?= h(t('lp_installing')) ?>"><i class="bi bi-upload me-1"></i><?= h(t('lp_upload_btn')) ?></button>
		</form>
	</div>
	<?php else: ?><div class="card-footer small text-body-secondary"><?= h(t('upd_disabled')) ?></div><?php endif; ?>
</div>
<?php $libs = array_values(array_filter(NuLibraries::all(), static fn($l) => $l['kind'] === 'bundled')); // in the package (0.8.229); the others: card above ?>
<?php $libProblems = count(array_filter($libs, static fn($l) => $l['status'] !== 'ok' && !($l['kind'] === 'external' && $l['status'] === 'missing'))); ?>
<details class="card shadow-sm mb-4" id="updLibraries">
	<summary class="card-header fw-semibold d-flex align-items-center gap-2"><span class="me-auto"><i class="bi bi-chevron-right nuLibChevron me-1" aria-hidden="true"></i><?= h(t('upd_libs') . ' · ' . t('upd_libs_bundled')) ?>
		<span class="badge text-bg-secondary ms-1"><?= count($libs) ?></span><?php if ($libProblems): ?><span class="badge text-bg-warning ms-1"><i class="bi bi-exclamation-triangle me-1"></i><?= $libProblems ?></span><?php endif; ?></span>
		<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('upd_libs_hint')) ?>" title="<?= h(t('upd_libs_hint')) ?>"><i class="bi bi-info-circle"></i></span></summary>
	<div class="table-responsive">
		<table class="table table-sm nu-table mb-0 small">
			<thead><tr><th><?= h(t('upd_libs_name')) ?></th><th><?= h(t('upd_libs_version')) ?></th><th><?= h(t('upd_libs_where')) ?></th><th class="d-none d-md-table-cell"><?= h(t('upd_libs_use')) ?></th><th class="d-none d-lg-table-cell"><?= h(t('upd_libs_license')) ?></th><th><?= h(t('upd_libs_state')) ?></th></tr></thead>
			<tbody>
			<?php $lastKind = ''; foreach ($libs as $l): if ($l['kind'] !== $lastKind): $lastKind = $l['kind']; ?>
				<tr class="nuLibGroup"><td colspan="6" class="fw-semibold text-body-secondary"><?= h(t($l['kind'] === 'bundled' ? 'upd_libs_bundled' : 'upd_libs_external')) ?></td></tr>
			<?php endif;
				[$icon, $text] = match ($l['status']) {
					'ok' => ['bi-check-circle text-success', t('upd_libs_ok') . ($l['note'] !== '' ? ' – ' . t($l['note']) : '')],
					'missing' => $l['kind'] === 'bundled' ? ['bi-x-circle text-danger', t('upd_libs_missing')] : ['bi-dash-circle text-body-secondary', t('vendor_missing')],
					'old' => ['bi-exclamation-triangle text-warning', t('upd_libs_old', $l['note'])],
					'differs' => ['bi-exclamation-triangle text-warning', t('upd_libs_differs')],
					default => ['bi-exclamation-triangle text-warning', $l['note'] !== '' ? t($l['note']) : t('upd_libs_ok')],
				}; ?>
				<tr><td class="fw-semibold"><?= h($l['name']) ?></td><td><?= h($l['version'] !== '' ? $l['version'] : '–') ?><?php if ($l['local'] !== ''): $lt = t('upd_libs_local') . "\n\n" . $l['local']; ?>
					<span class="nuInfoTip ms-1" tabindex="0" role="button" data-bs-toggle="tooltip" data-bs-trigger="hover focus click" data-bs-custom-class="nuTipWide nuTipPre" aria-label="<?= h($lt) ?>" title="<?= h($lt) ?>"><i class="bi bi-wrench-adjustable"></i></span><?php endif; ?></td>
					<td><code class="small text-break"><?= h($l['path'] !== '' ? $l['path'] : '–') ?></code></td><td class="d-none d-md-table-cell"><?= h($l['use']) ?></td>
					<td class="d-none d-lg-table-cell"><?= h($l['license']) ?></td><td class="nuLibState"><i class="bi <?= $icon ?> me-1"></i><?= h($text) ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</details>
</div>
<div class="tab-pane fade<?= $updTab === 'system' ? ' show active' : '' ?>" id="updPane-system" role="tabpanel" aria-labelledby="updTabBtn-system" tabindex="0">
		<div class="card shadow-sm mb-4<?= $reqOk ? '' : ' border-danger' ?>" id="updRequirements">
			<div class="card-header fw-semibold"><?= h(t('upd_requirements')) ?></div>
			<ul class="list-group list-group-flush small">
				<?php foreach ($req as [$label, $ok, $detail]): ?>
					<li class="list-group-item d-flex gap-2<?= $ok ? '' : ' list-group-item-danger' ?>"><i class="bi <?= $ok ? 'bi-check-circle text-success' : 'bi-x-circle text-danger' ?>"></i><span class="me-auto"><?= h($label) ?><?php if (!$ok && $label === t('upd_req_writable')): $tip = NuUpdate::writableHelp(); ?>
						<span class="nuInfoTip ms-1" tabindex="0" role="button" data-bs-toggle="tooltip" data-bs-trigger="hover focus click" data-bs-custom-class="nuTipWide nuTipPre" aria-label="<?= h($tip) ?>" title="<?= h($tip) ?>"><i class="bi bi-info-circle"></i></span><?php endif; ?></span><span class="text-body-secondary text-end text-break"><?= h($detail) ?></span></li>
				<?php endforeach; ?>
			</ul>
			<div class="card-footer small text-body-secondary"><?= h(t('upd_req_hint')) ?></div>
		</div>
	
<div class="card shadow-sm mb-4" id="updStepsCard">
	<div class="card-header fw-semibold"><?= h(t('upd_db_steps')) ?></div>
	<ul class="list-group list-group-flush small" id="updSteps">
		<?php foreach (NuMigrate::status() as $s): ?>
			<li class="list-group-item d-flex flex-wrap gap-2 nuUpdStep"><i class="bi <?= $s['applied'] ? 'bi-check-circle text-success' : 'bi-hourglass text-warning' ?>"></i>
				<code><?= h($s['id']) ?></code><span class="me-auto nuUpdStepDesc"><?= h($s['description']) ?></span>
				<span class="text-body-secondary text-nowrap"><?= $s['applied'] ? h(date(nu_dfmt('d.m.Y H:i'), strtotime($s['applied'])) . ' · ' . $s['version']) : h(t('upd_pending')) ?></span></li>
		<?php endforeach; ?>
	</ul>
</div>
<?php if (!$check): // integrity check of the database (0.8.698): on request – it also tries a scratch table ?>
<div class="card shadow-sm mb-4" id="updIntegrityNow">
	<div class="card-header fw-semibold d-flex flex-wrap align-items-center gap-2"><span><i class="bi bi-clipboard2-pulse me-2"></i><?= h(t('ic_title')) ?></span><?= NuListView::infoTip(t('ic_tip')) ?>
		<a class="btn btn-sm nuBtnAct ms-auto" href="<?= h(nu_url('update', ['tab' => 'system', 'integrity' => 1])) ?>#updIntegrityNow" id="updIntegrityRun"><i class="bi bi-play-circle me-1"></i><?= h(t(isset($_GET['integrity']) ? 'ic_run_again' : 'ic_run')) ?></a></div>
	<div class="card-body">
		<?php if (isset($_GET['integrity'])): $icR = NuIntegrity::run(); ?>
			<?= NuIntegrity::html($icR) ?>
			<p class="small text-body-secondary mt-2 mb-0"><?= h(t('ic_done_in', (int)$icR['checks'], (int)$icR['ms'])) ?></p>
		<?php else: ?>
			<p class="small text-body-secondary mb-0"><?= h(t('ic_intro')) ?></p>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>
<?php if (!$check): $cmpNow = ($e = NuSchemaCheck::installed()) ? NuSchemaCheck::compare($e) : null; ?>
<div class="card shadow-sm" id="updSchema">
	<div class="card-header fw-semibold"><i class="bi bi-database-gear me-2"></i><?= h(t('upd_schema_title')) ?></div>
	<div class="card-body">
		<?= $schemaList($cmpNow, 'updSchemaForm') ?>
		<?php if ($cmpNow && ($cmpNow['missing'] || $cmpNow['changed']) && $updOn): ?>
			<form method="post" action="<?= h(nu_url('update')) ?>" id="updSchemaForm" class="mt-3" data-confirm="<?= h(t('upd_schema_confirm')) ?>" data-confirm-ok="<?= h(t('upd_schema_apply')) ?>" data-confirm-class="btn btn-primary">
				<?= nu_csrf_field() ?><input type="hidden" name="action" value="schema_apply">
				<button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-database-check me-1"></i><?= h(t('upd_schema_apply')) ?></button>
				<span class="small text-body-secondary ms-2"><?= h(t('upd_schema_backup_hint')) ?></span>
			</form>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>
</div>
</div>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nulibpacks.js') . '"></script><script src="' . nu_asset('assets/js/nubackups.js') . '"></script>');
