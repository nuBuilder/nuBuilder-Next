<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * App cloner (globeadmin, Admin tools): the whole application – database and files – into another database / folder, or the
 * database as an SQL file. POST action=dry|run streams the log; action=export downloads the SQL. See core/nuappcloner.php.
 * Saved configurations (0.8.276): nuBuilder's table zzzzsys_app_cloner (core/nuappclonerconfig.php) – list, ?id=<id> / ?id=new;
 * POST action=save|clone|delete. Export / dry run / run work with the values on the page, saved or not.
 */

// admin rights (0.8.323): the globeadmin, or a user whose admin role has the right (core/nuadmin.php)
NuAdmin::require('appcloner', 'change');
NuSecurity::requireRecentAuth(nu_url('appcloner'));   // password again when the last confirmation is older than 15 minutes (0.8.207)
require_once NU_ROOT . '/core/nuappcloner.php';
$db  = nu_db();
$cfg = nu_config();
$isMs    = $db->type === 'sqlsrv';                                         // SQL Server: same page, a few options differ
$dbOk = $db->type === 'mysql' || $isMs;                                  // = the cloner supports this database
NuAppClonerConfig::ensureTable();
$CT = NuAppClonerConfig::TABLE;
$id  = (string)($_GET['id'] ?? '');
$row = null;
if ($id !== '' && $id !== 'new') {
	$row = NuAppClonerConfig::get($id);
	if (!$row) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
}
[$srcCharset, $srcCollation] = $dbOk ? NuAppCloner::charset($db) : ['utf8mb4', 'utf8mb4_unicode_ci'];
$srcDb   = $isMs ? (string)$db->value('SELECT DB_NAME()') : ($dbOk ? (string)$db->value('SELECT DATABASE()') : '');
$defPort = $isMs ? 1433 : 3306;
$nubPath = NuBuilderLib::path();
$stamp   = date('Y-m-d');

// clone (0.8.680, as nuBuilder Forte): ?id=new&clone=<id> opens a copy of a configuration that is saved only with Save
$cloneId = $id === 'new' ? (string)($_GET['clone'] ?? '') : '';
$src = $cloneId !== '' ? NuAppClonerConfig::get($cloneId) : null;
if ($cloneId !== '' && !$src) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
$def = [
	'code' => '', 'description' => '', 'same_login' => true,
	'host' => (string)$cfg['nuConfigDBHost'], 'port' => (string)($cfg['nuConfigDBPort'] ?: $defPort), 'user' => (string)$cfg['nuConfigDBUser'], 'password' => '',
	'db' => $srcDb . '_clone_' . str_replace('-', '', $stamp), 'create_db' => true, 'db_mode' => 'fail',
	'charset' => $srcCharset, 'collation' => $srcCollation, 'parts' => array_keys(NuAppCloner::PARTS), 'insert' => 'INSERT', 'skip_sessions' => true,
	'tables_all' => true, 'tables' => [], 'files' => false, 'source_path' => $nubPath,
	'target_path' => dirname($nubPath) . '/' . basename($nubPath) . '_clone_' . str_replace('-', '', $stamp),
	'file_mode' => 'fail', 'excludes' => implode("\n", NuAppCloner::DEFAULT_EXCLUDES), 'stored_password' => false,
];
if (nu_is_post()) {
	$in = [
		'code' => nu_post('code'), 'description' => nu_post('description'),
		'same_login' => !empty($_POST['same_login']),
		'host' => nu_post('host') ?: $def['host'], 'port' => nu_post('port') ?: $def['port'],
		'user' => nu_post('user') ?: $def['user'], 'password' => (string)($_POST['password'] ?? ''),
		'db' => nu_post('db') ?: $def['db'], 'create_db' => !empty($_POST['create_db']),
		'db_mode' => in_array(nu_post('db_mode'), ['fail', 'clear', 'add'], true) ? nu_post('db_mode') : 'fail',
		'charset' => nu_post('charset') ?: $srcCharset, 'collation' => nu_post('collation') ?: $srcCollation,
		'parts' => array_values(array_intersect(array_keys(NuAppCloner::PARTS), (array)($_POST['parts'] ?? []))),
		'insert' => !$isMs && in_array(nu_post('insert'), ['INSERT', 'INSERT IGNORE', 'REPLACE'], true) ? nu_post('insert') : 'INSERT',
		'skip_sessions' => !empty($_POST['skip_sessions']),
		'tables_all' => !empty($_POST['tables_all']), 'tables' => array_map('strval', (array)($_POST['tables'] ?? [])),
		'files' => !empty($_POST['files']),
		'source_path' => nu_post('source_path') ?: $nubPath, 'target_path' => nu_post('target_path') ?: $def['target_path'],
		'file_mode' => in_array(nu_post('file_mode'), ['fail', 'clear', 'merge'], true) ? nu_post('file_mode') : 'fail',
		'excludes' => (string)($_POST['excludes'] ?? ''),
		'stored_password' => ($row ?? $src) && trim((string)($row ?? $src)['cla_target_password']) !== '',
	];
} else {
	$in = $row || $src ? NuAppClonerConfig::toInput($row ?? $src, $def, $cfg, $defPort) : $def;
	if ($src) $in['code'] = NuAppClonerConfig::cloneCode((string)$src['cla_code']);
}
if ($in['same_login']) { $in['host'] = $def['host']; $in['port'] = $def['port']; $in['user'] = $def['user']; }
// the target password: nuconfig.php's with "same login", else the one typed – or the one nuBuilder saved in the configuration
$targetPw = $in['same_login'] ? (string)$cfg['nuConfigDBPassword'] : ($in['password'] !== '' ? $in['password'] : (string)(($row ?? $src)['cla_target_password'] ?? ''));
$errors = [];

$action = nu_is_post() ? nu_post('action') : '';
$options = static fn(bool $dry, bool $export) => [
	'target' => ['host' => $in['host'], 'port' => (int)$in['port'], 'user' => $in['user'], 'password' => $targetPw,
		'db' => $in['db'], 'charset' => $in['charset'], 'collation' => $in['collation']],
	'create_db' => $in['create_db'], 'db_mode' => $in['db_mode'], 'parts' => $in['parts'], 'insert' => $in['insert'],
	'tables' => $in['tables_all'] ? null : $in['tables'], 'files' => $in['files'] && !$export, 'source_path' => $in['source_path'], 'target_path' => $in['target_path'],
	'file_mode' => $in['file_mode'], 'excludes' => preg_split('/\R/', $in['excludes']) ?: [], 'dry' => $dry, 'export' => $export, 'skip_sessions' => $in['skip_sessions'],
];

// ------------------------------------------------------------------ saved configurations: save / clone / delete
if ($action === 'save') {
	[$newId, $errors] = NuAppClonerConfig::save($in, $row ? $id : '', $row ? null : $src);
	if (!$errors) {
		NuLog::write($row ? 'app_cloner_changed' : 'app_cloner_created', $CT, (string)$newId, $row
			? ['fields' => NuLog::diff($row, NuAppClonerConfig::get((string)$newId) ?? [])] : ['code' => trim((string)$in['code'])] + ($src ? ['clone_of' => $cloneId] : []));
		nu_flash('success', t('saved'));
		nu_redirect(nu_url('appcloner', ['id' => $newId]));
	}
}
if ($action === 'delete' && $row) {
	$db->delete($CT, 'zzzzsys_app_cloner_id', $id);
	NuLog::write('app_cloner_deleted', $CT, $id, ['code' => (string)$row['cla_code']]);
	nu_flash('success', t('ac_deleted'));
	nu_redirect(nu_url('appcloner'));
}

// ------------------------------------------------------------------ SQL file (streamed)
if ($action === 'export' && $dbOk) {
	session_write_close();
	nu_time_limit(0);
	while (ob_get_level()) ob_end_clean();
	header('Content-Type: application/sql; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $srcDb) . '_' . date('Ymd_His') . '.sql"');
	header('X-Content-Type-Options: nosniff');
	try {
		(new NuAppCloner($db, $options(false, true)))->run(static function () {}, static function (string $sql) { echo $sql; flush(); });
		NuLog::write('app_exported', 'database', $srcDb, []);
	} catch (Throwable $e) {
		echo "\n-- ERROR: " . str_replace("\n", ' ', $e->getMessage()) . "\n";
	}
	exit;
}

$listMode = $id === '' && !nu_is_post();
$tableList = $dbOk && !$listMode ? NuAppCloner::objects($db) : [];
nu_layout_start(t('ac_title'), 'home');
$tip = static fn(string $text) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<?php if ($listMode): ?><li class="breadcrumb-item active" aria-current="page"><?= h(t('ac_title')) ?></li>
	<?php else: ?><li class="breadcrumb-item"><a href="<?= h(nu_url('appcloner')) ?>"><?= h(t('ac_title')) ?></a></li><li class="breadcrumb-item active" aria-current="page"><?= h($row ? (string)$row['cla_code'] : t('ac_new')) ?></li><?php endif; ?>
</ol></nav>
<?php if ($listMode):
	// ------------------------------------------------------------------ list of saved configurations
	$q = trim((string)($_GET['q'] ?? ''));
	$rows = NuAppClonerConfig::all($q);
	?>
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-copy me-2"></i><?= h(t('ac_title')) ?><?= $tip(t('ac_configs_info')) ?></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?></div>
	</div>
	<?php if (count($rows) > 10 || $q !== ''): // the search only when there is something to search (0.8.690) ?>
	<form class="card card-body shadow-sm mb-3 py-3" method="get" action="index.php" role="search">
		<input type="hidden" name="p" value="appcloner">
		<div class="row g-2 align-items-end"><div class="col-md-6"><?= nu_list_search($q, '', '', false, $q !== '' ? nu_url('appcloner') : null) ?></div></div>
	</form>
	<?php endif; ?>
	<div class="card shadow-sm">
		<div class="card-header nuTitleBar">
			<span class="small text-body-secondary"><?= h(t('n_records', count($rows))) ?></span>
			<div class="d-flex flex-wrap gap-2"><a class="btn nuAddBtn" href="<?= h(nu_url('appcloner', ['id' => 'new'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></a></div>
		</div>
		<div class="table-responsive">
			<table class="table table-hover align-middle mb-0 nu-table">
				<thead><tr><th><?= h(t('ac_code')) ?></th><th><?= h(t('ac_description')) ?></th><th><?= h(t('ac_target_db')) ?></th><th><?= h(t('ac_files_col')) ?></th></tr></thead>
				<tbody>
				<?php if (!$rows): ?><tr><td colspan="4" class="text-center text-body-secondary py-5"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
				<?php foreach ($rows as $r): $edit = nu_url('appcloner', ['id' => (string)$r['zzzzsys_app_cloner_id']]); ?>
					<tr data-href="<?= h($edit) ?>" tabindex="0">
						<td class="fw-semibold nuMainCol"><a class="text-decoration-none" href="<?= h($edit) ?>"><?= h((string)$r['cla_code'] !== '' ? $r['cla_code'] : '–') ?></a></td>
						<td class="small text-break"><?= h($r['cla_description']) ?></td>
						<td class="small font-monospace text-break"><?= h($r['cla_target_db']) ?><?php if ((string)$r['cla_target_host'] !== ''): ?> <span class="text-body-secondary">@ <?= h($r['cla_target_host']) ?></span><?php endif; ?></td>
						<td class="small font-monospace text-break"><?= (string)$r['cla_copy_files'] === '1' ? h($r['cla_target_path']) : '<span class="text-body-secondary">–</span>' ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php nu_layout_end();
	return;
endif; ?>
<div class="nuTitleBar mb-3">
	<h1 class="h4 mb-0"><i class="bi bi-copy me-2"></i><?= h(t('ac_title')) ?></h1>
	<div class="d-flex flex-wrap gap-2"><?= nu_back_button('appcloner', nu_url('appcloner')) ?></div>
</div>
<?php
// ------------------------------------------------------------------ dry run / run: the log is shown while it runs
if (($action === 'dry' || $action === 'run') && $dbOk):
	session_write_close();
	nu_time_limit(0);
	nu_ignore_abort();
	?>
	<div class="card shadow-sm mb-3"><div class="card-header fw-semibold"><?= h(t($action === 'dry' ? 'ac_log_dry' : 'ac_log_run')) ?></div>
	<ol class="list-unstyled small font-monospace mb-0 p-3 nuAcLog" id="acLog">
	<?php
	while (ob_get_level()) ob_end_flush();
	ob_implicit_flush(true);
	$icons = ['step' => 'bi-caret-right-fill text-primary', 'ok' => 'bi-check2 text-success', 'warn' => 'bi-exclamation-triangle text-warning', 'info' => 'bi-dot text-body-secondary', 'error' => 'bi-x-octagon text-danger'];
	$say = static function (string $line, string $kind) use ($icons) {
		echo '<li class="nuAcLine nuAcLine-' . h($kind) . '"><i class="bi ' . $icons[$kind] . ' me-1"></i>' . h($line) . "</li>\n";
		flush();
	};
	$res = null;
	try { $res = (new NuAppCloner($db, $options($action === 'dry', false)))->run($say); }
	catch (Throwable $e) { error_log('nuBuilder Next app cloner: ' . $e->getMessage()); $say($e instanceof RuntimeException || $e instanceof PDOException ? $e->getMessage() : t('ac_err_failed'), 'error'); }
	?>
	</ol>
	<?php if ($res): $st = $res['stats']; ?>
		<div class="card-footer small">
			<?= h(t('ac_summary', $st['tables'], $st['views'], $st['rows'], $st['functions'] + $st['procedures'], $st['triggers'], $st['events'], $st['files'])) ?>
			<?php if ($res['warnings']): ?> · <span class="text-warning-emphasis"><?= h(t('ac_n_warnings', count($res['warnings']))) ?></span><?php endif; ?>
		</div>
	<?php endif; ?>
	</div>
<?php endif; ?>

<?php if (!$dbOk): ?>
	<div class="alert alert-warning"><?= h(t('ac_err_mysql')) ?></div>
<?php else: ?>
<?php
	$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
	$fb = static fn(string $k) => '<div class="invalid-feedback">' . h($errors[$k] ?? '') . '</div>';
	$other = array_diff_key($errors, ['code' => 1, 'description' => 1]);
	$labels = ['host' => t('ac_host'), 'db' => t('ac_db_name'), 'user' => t('login_username'), 'charset' => t('ac_charset'), 'collation' => t('ac_collation'),
		'source_path' => t('ac_source_path'), 'target_path' => t('ac_target_path'), 'excludes' => t('ac_excludes')];
	?>
<?php if ($other): ?><div class="alert alert-danger"><?php foreach ($other as $k => $m): ?><div><?= h(($labels[$k] ?? $k) . ': ' . $m) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" action="<?= h(nu_url('appcloner', ['id' => $row ? $id : 'new', 'clone' => $cloneId])) ?>" id="acForm"<?= $src ? ' data-nu-clone="' . h(t('form_clone_badge')) . '"' : '' ?> data-nu-guard data-confirm-run="<?= h(t('ac_confirm_run')) ?>">
	<?= nu_csrf_field() ?>
	<div class="nuTitleBar mb-3">
		<h2 class="h5 mb-0"><?= h($row ? (string)$row['cla_code'] : t('ac_new')) ?></h2>
		<div class="d-flex flex-wrap gap-2">
			<button data-nu-save class="btn nuBtnAct nuBtnSave" type="submit" name="action" value="save" formnovalidate><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
			<?php if ($row): ?>
				<a class="btn nuBtnAct" href="<?= h(nu_url('appcloner', ['id' => 'new', 'clone' => $id])) ?>" id="acClone"><i class="bi bi-copy me-1"></i><?= h(t('clone')) ?></a>
				<button class="btn nuBtnAct nuBtnDel" type="submit" name="action" value="delete" formnovalidate data-confirm-submit="<?= h(t('ac_delete_confirm')) ?>"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button>
			<?php endif; ?>
		</div>
	</div>
	<div class="card shadow-sm mb-3"><div class="card-body"><div class="row g-2">
		<div class="col-md-4"><label class="form-label small" for="acCode"><?= h(t('ac_code')) ?> <span class="text-danger">*</span><?= nu_unique_mark(true) ?><?= $tip(t('ac_code_info')) ?></label>
			<input class="form-control<?= $err('code') ?>" id="acCode" name="code" maxlength="100" value="<?= h($in['code']) ?>" autocomplete="off"><?= $fb('code') ?></div>
		<div class="col-md-8"><label class="form-label small" for="acDesc"><?= h(t('ac_description')) ?></label>
			<input class="form-control<?= $err('description') ?>" id="acDesc" name="description" maxlength="100" value="<?= h($in['description']) ?>" autocomplete="off"><?= $fb('description') ?></div>
	</div></div></div>
	<div class="row g-3">
		<div class="col-xl-6">
			<div class="card shadow-sm mb-3">
				<div class="card-header fw-semibold"><i class="bi bi-database me-2"></i><?= h(t('ac_target_db')) ?></div>
				<div class="card-body">
					<div class="small text-body-secondary mb-2"><?= h(t('ac_source_db', $srcDb, (string)$cfg['nuConfigDBHost'])) ?></div>
					<div class="row g-2">
						<div class="col-md-7"><label class="form-label small" for="acDb"><?= h(t('ac_db_name')) ?> <span class="text-danger">*</span></label><input class="form-control" id="acDb" name="db" value="<?= h($in['db']) ?>" required pattern="[A-Za-z0-9_$\-]{1,64}" autocomplete="off"></div>
						<div class="col-md-5 d-flex align-items-end"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="create_db" value="1"<?= $in['create_db'] ? ' checked' : '' ?>> <span class="form-check-label small"><?= h(t('ac_create_db')) ?></span></label></div>
					</div>
					<label class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="same_login" value="1" id="acSame"<?= $in['same_login'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('ac_same_login')) ?></span></label>
					<div class="row g-2 mt-1" id="acLogin">
						<div class="col-8 col-md-5"><label class="form-label small" for="acHost"><?= h(t('ac_host')) ?></label><input class="form-control" id="acHost" name="host" value="<?= h($in['host']) ?>" autocomplete="off"></div>
						<div class="col-4 col-md-2"><label class="form-label small" for="acPort"><?= h(t('ac_port')) ?></label><input class="form-control" id="acPort" name="port" value="<?= h($in['port']) ?>" inputmode="numeric"></div>
						<div class="col-6 col-md-5"><label class="form-label small" for="acUser"><?= h(t('login_username')) ?></label><input class="form-control" id="acUser" name="user" value="<?= h($in['user']) ?>" autocomplete="off"></div>
						<div class="col-6 col-md-5"><label class="form-label small" for="acPw"><?= h(t('login_password')) ?></label><?= $tip(t('ac_pw_info')) ?><input class="form-control" type="password" id="acPw" name="password" autocomplete="new-password"<?= $in['stored_password'] ? ' placeholder="' . h(t('ac_pw_stored')) . '"' : '' ?>></div>
					</div>
					<div class="row g-2 mt-1">
						<div class="col-md-6"><label class="form-label small" for="acMode"><?= h(t('ac_db_mode')) ?></label>
							<select class="form-select form-select-sm" id="acMode" name="db_mode">
								<?php foreach (['fail', 'add', 'clear'] as $m): ?><option value="<?= $m ?>"<?= $in['db_mode'] === $m ? ' selected' : '' ?>><?= h(t('ac_db_mode_' . $m)) ?></option><?php endforeach; ?>
							</select></div>
						<?php if (!$isMs): // SQL Server: the collation is enough (no character set) ?>
						<div class="col-6 col-md-3"><label class="form-label small" for="acCs"><?= h(t('ac_charset')) ?></label><input class="form-control form-control-sm" id="acCs" name="charset" value="<?= h($in['charset']) ?>"></div>
						<?php endif; ?>
						<div class="<?= $isMs ? 'col-md-6' : 'col-6 col-md-3' ?>"><label class="form-label small" for="acColl"><?= h(t('ac_collation')) ?></label><input class="form-control form-control-sm" id="acColl" name="collation" value="<?= h($in['collation']) ?>"></div>
					</div>
				</div>
			</div>

			<div class="card shadow-sm mb-3">
				<div class="card-header fw-semibold"><i class="bi bi-list-check me-2"></i><?= h(t('ac_contents')) ?></div>
				<div class="card-body">
					<div class="row g-2">
						<?php foreach ([['ac_g_struct', ['sys_struct', 'user_struct']], ['ac_g_data', ['sys_rows', 'app_rows', 'user_rows']], ['ac_g_prog', ['functions', 'procedures', 'triggers', 'events']]] as [$g, $keys]): ?>
							<div class="col-md-4"><div class="small fw-semibold mb-1"><?= h(t($g)) ?></div>
								<?php foreach ($keys as $k): $off = $isMs && $k === 'events'; // SQL Server has no events ?><label class="form-check small"<?= $off ? ' title="' . h(t('ac_events_mssql')) . '"' : '' ?>><input class="form-check-input" type="checkbox" name="parts[]" value="<?= $k ?>"<?= !$off && in_array($k, $in['parts'], true) ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>> <span class="form-check-label"><?= h(t(NuAppCloner::PARTS[$k])) ?></span></label><?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="row g-2 mt-1 align-items-end">
						<div class="col-md-7"><label class="form-check small mb-1"><input class="form-check-input" type="checkbox" name="skip_sessions" value="1"<?= $in['skip_sessions'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('ac_skip_sessions_opt')) ?></span></label></div>
						<?php if (!$isMs): // INSERT IGNORE / REPLACE exist in MySQL only ?>
						<div class="col-md-5"><label class="form-label small" for="acIns"><?= h(t('ac_insert')) ?></label>
							<select class="form-select form-select-sm" id="acIns" name="insert"><?php foreach (['INSERT', 'INSERT IGNORE', 'REPLACE'] as $x): ?><option<?= $in['insert'] === $x ? ' selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<div class="col-xl-6">
			<div class="card shadow-sm mb-3">
				<div class="card-header d-flex align-items-center gap-2">
					<span class="fw-semibold me-auto"><i class="bi bi-table me-2"></i><?= h(t('ac_tables')) ?></span>
					<label class="form-check form-switch mb-0 small"><input class="form-check-input" type="checkbox" name="tables_all" value="1" id="acAll"<?= $in['tables_all'] ? ' checked' : '' ?>> <span class="form-check-label"><?= h(t('ac_tables_all')) ?></span></label>
				</div>
				<div class="card-body" id="acTablesBox">
					<div class="d-flex gap-2 mb-2"><input type="search" class="form-control form-control-sm" id="acTableFilter" placeholder="<?= h(t('search_ph')) ?>" aria-label="<?= h(t('search')) ?>">
						<button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" id="acTablesOn"><?= h(t('am_select_shown')) ?></button>
						<button type="button" class="btn btn-sm btn-outline-secondary" id="acTablesOff"><?= h(t('am_select_none')) ?></button></div>
					<div class="nuAcTables border rounded">
						<?php foreach ($tableList as $tb): ?>
							<label class="d-flex align-items-center gap-2 px-2 py-1 small border-bottom nuAcTable" data-name="<?= h(strtolower($tb['name'])) ?>">
								<input class="form-check-input m-0" type="checkbox" name="tables[]" value="<?= h($tb['name']) ?>"<?= $in['tables_all'] || in_array($tb['name'], $in['tables'], true) ? ' checked' : '' ?>>
								<span class="font-monospace flex-grow-1 text-truncate"><?= h($tb['name']) ?></span>
								<?php if ($tb['type'] === 'VIEW'): ?><span class="badge bg-info-subtle text-info-emphasis border fw-normal">VIEW</span><?php endif; ?>
								<?php if ($tb['system']): ?><span class="badge bg-secondary-subtle text-secondary-emphasis border fw-normal">nuBuilder Forte</span><?php endif; ?>
								<?php if ($tb['type'] === 'TABLE'): ?><span class="text-body-secondary">~<?= number_format($tb['rows'], 0, '.', '\'') ?></span><?php endif; ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<div class="card shadow-sm mb-3">
				<div class="card-header"><label class="form-check form-switch mb-0 fw-semibold"><input class="form-check-input" type="checkbox" name="files" value="1" id="acFiles"<?= $in['files'] ? ' checked' : '' ?>> <span class="form-check-label"><i class="bi bi-folder2-open me-1"></i><?= h(t('ac_files')) ?></span></label></div>
				<div class="card-body" id="acFilesBox">
					<label class="form-label small" for="acSrc"><?= h(t('ac_source_path')) ?></label><input class="form-control form-control-sm font-monospace mb-2" id="acSrc" name="source_path" value="<?= h($in['source_path']) ?>">
					<label class="form-label small" for="acTgt"><?= h(t('ac_target_path')) ?></label><input class="form-control form-control-sm font-monospace mb-2" id="acTgt" name="target_path" value="<?= h($in['target_path']) ?>">
					<div class="row g-2">
						<div class="col-md-6"><label class="form-label small" for="acFMode"><?= h(t('ac_file_mode')) ?></label>
							<select class="form-select form-select-sm" id="acFMode" name="file_mode"><?php foreach (['fail', 'merge', 'clear'] as $m): ?><option value="<?= $m ?>"<?= $in['file_mode'] === $m ? ' selected' : '' ?>><?= h(t('ac_file_mode_' . $m)) ?></option><?php endforeach; ?></select></div>
						<div class="col-md-6"><label class="form-label small" for="acEx"><?= h(t('ac_excludes')) ?></label><textarea class="form-control form-control-sm font-monospace" id="acEx" name="excludes" rows="3"><?= h($in['excludes']) ?></textarea></div>
					</div>
					<div class="form-text"><?= h(t('ac_files_hint')) ?></div>
				</div>
			</div>
		</div>
	</div>
	<div class="d-flex flex-wrap gap-2 justify-content-end mb-2">
		<button class="btn btn-outline-secondary" type="submit" name="action" value="export" formnovalidate><i class="bi bi-download me-1"></i><?= h(t('ac_export')) ?></button>
		<button class="btn btn-outline-primary" type="submit" name="action" value="dry"><i class="bi bi-eye me-1"></i><?= h(t('ac_dry')) ?></button>
		<button class="btn btn-primary" type="submit" name="action" value="run" id="acRun"><i class="bi bi-play-fill me-1"></i><?= h(t('ac_run')) ?></button>
	</div>
	<div class="small text-body-secondary text-end"><?= h(t('ac_hint')) ?></div>
	<?php if ($isMs): ?><div class="small text-body-secondary text-end mt-1"><i class="bi bi-info-circle me-1"></i><?= h(t('ac_mssql_hint')) ?></div><?php endif; ?>
</form>
<?php endif;
nu_layout_end('<script src="' . nu_asset('assets/js/nuappcloner.js') . '"></script>');
