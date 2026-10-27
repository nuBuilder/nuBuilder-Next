<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Admin tools → Custom code (0.8.444 "Setup code", renamed 0.8.523). Two layers:
 *   Form pages (Form JavaScript / Form CSS / Form includes): nuBuilder's Setup → Header, Style and Include (<link> / <script> tags) – the same
 * columns of zzzzsys_setup, so nuBuilder and nuBuilder Next stay in step. Used on every form page (pages/nuform.php, NuIncludes::setupTags).
 * POST action save: only changed fields are written; a field someone else changed since the page was opened is refused (hash of the
 * loaded values). Tabs in the address (#header, #style, #include – nuapp.js data-nu-hash-tabs); editors: assets/js/nuglobalcode.js.
 *   All pages (0.8.523): Global JS (#gjs) and Global CSS (#gcss) + where they run (scope) – NuCustomCode, nuBuilder Next's own settings.
 */

NuAdmin::require('globalcode');
NuSecurity::requireRecentAuth(nu_url('globalcode'));   // code for every user's form pages: password again after 15 minutes

const NU_SETUP_CODE = ['header' => 'set_header', 'style' => 'set_style', 'include' => 'set_include'];

$db = nu_db();
$exists = $db->tableExists('zzzzsys_setup');
$cols = [];
$row = null;
if ($exists) {
	$have = array_map('strtolower', array_keys($db->columns('zzzzsys_setup')));
	$cols = array_filter(NU_SETUP_CODE, static fn($c) => in_array($c, $have, true));   // older nuBuilder: no set_include / set_style
	$row = $cols ? $db->page('SELECT zzzzsys_setup_id, ' . implode(', ', $cols) . ' FROM zzzzsys_setup', [], 'zzzzsys_setup_id', 1, 0)[0] ?? null : null;
}
$values = [];
foreach ($cols as $k => $c) $values[$k] = str_replace("\r\n", "\n", (string)($row[$c] ?? ''));
$hashOf = static fn(string $v): string => hash('sha256', $v);

// Global JS & CSS (0.8.523): nuBuilder Next's own settings – also without zzzzsys_setup
$global = NuCustomCode::get();
$gValues = ['gjs' => str_replace("\r\n", "\n", $global['js']), 'gcss' => str_replace("\r\n", "\n", $global['css'])];

if (nu_is_post() && nu_post('action') === 'save') {
	NuAdmin::require('globalcode', 'change');
	$set = [];
	$conflict = [];
	$gNew = ['gjs' => null, 'gcss' => null];
	$unwrapped = false;
	foreach ($gValues as $k => $old) {
		if (!isset($_POST['code'][$k]) || !is_string($_POST['code'][$k])) continue;
		$new = str_replace("\r\n", "\n", $_POST['code'][$k]);
		// Global CSS: a surrounding <style>…</style> is removed – it is served as a .css file (0.8.557)
		if ($k === 'gcss' && ($plain = NuCustomCode::unwrapStyle($new)) !== null) { $new = $plain; $unwrapped = true; }
		if ($new === $old) continue;
		if ((string)($_POST['hash'][$k] ?? '') !== $hashOf($old)) { $conflict[] = $k; continue; }
		$gNew[$k] = $new;
	}
	$gScope = isset($_POST['scope_sent']) ? array_map(static fn($k) => isset($_POST['scope'][$k]), array_combine(array_keys(NuCustomCode::SCOPES), array_keys(NuCustomCode::SCOPES))) : null;
	foreach ($cols as $k => $c) {
		if (!isset($_POST['code'][$k]) || !is_string($_POST['code'][$k])) continue;
		$new = str_replace("\r\n", "\n", $_POST['code'][$k]);
		if ($new === $values[$k]) continue;
		if ((string)($_POST['hash'][$k] ?? '') !== $hashOf($values[$k])) { $conflict[] = $k; continue; }   // changed by someone else meanwhile
		$set[$c] = $new;
	}
	$gChanged = [];
	if (!$conflict && ($gNew['gjs'] !== null || $gNew['gcss'] !== null || $gScope !== null)) {
		$gChanged = NuCustomCode::save($gNew['gjs'], $gNew['gcss'], $gScope);
		if ($gChanged) NuLog::write('custom_code_saved', 'zzzzsys_nunext_setting', 'custom', ['parts' => $gChanged,
			'chars' => array_filter(['js' => $gNew['gjs'] !== null ? mb_strlen($gNew['gjs']) : null, 'css' => $gNew['gcss'] !== null ? mb_strlen($gNew['gcss']) : null], static fn($v) => $v !== null)]);
		if ($gNew['gjs'] !== null && ($clash = NuCustomCode::hookClashes($gNew['gjs']))) nu_flash('warning', t('scode_hook_clash', implode(', ', $clash)));
	}
	if ($unwrapped && !$conflict) {
		nu_flash('info', t('scode_css_unwrapped'));
	}
	$gNames = array_map(static fn($k) => t('scode_tab_' . ['js' => 'gjs', 'css' => 'gcss', 'scope' => 'scope'][$k]), $gChanged);
	if ($conflict) {
		nu_flash('danger', t('scode_conflict', implode(', ', array_map(static fn($k) => t('scode_tab_' . $k), $conflict))));
		$values = array_merge($values, array_intersect_key(array_map(static fn($v) => str_replace("\r\n", "\n", (string)$v), (array)$_POST['code']), $values));
		$gValues = array_merge($gValues, array_intersect_key(array_map(static fn($v) => str_replace("\r\n", "\n", (string)$v), (array)$_POST['code']), $gValues));
	} elseif (!$set && !$gChanged) {
		nu_flash('info', t('scode_unchanged'));
		$st = preg_replace('/[^a-z]/', '', (string)nu_post('tab'));
		nu_redirect(nu_url('globalcode', $st !== '' ? ['tab' => $st] : []) . ($st !== '' ? '#' . $st : ''));   // ?tab=: the server opens it (0.8.451)
	} else {
		$names = [];
		if ($set && $row !== null) {
			$db->update('zzzzsys_setup', $set, 'zzzzsys_setup_id', (string)$row['zzzzsys_setup_id']);
			$names = array_map(static fn($c) => t('scode_tab_' . array_search($c, NU_SETUP_CODE, true)), array_keys($set));
			NuLog::write('setup_code_saved', 'zzzzsys_setup', (string)$row['zzzzsys_setup_id'],
				['fields' => array_keys($set), 'chars' => array_map(static fn($v) => mb_strlen($v), $set)]);
		}
		nu_flash('success', t('scode_saved', implode(', ', array_merge($names, $gNames))));
		$st = preg_replace('/[^a-z]/', '', (string)nu_post('tab'));
		nu_redirect(nu_url('globalcode', $st !== '' ? ['tab' => $st] : []) . ($st !== '' ? '#' . $st : ''));   // ?tab=: the server opens it (0.8.451)
	}
}

$canChange = NuAdmin::can('globalcode', 'change') && empty(NuAuth::user()['demo']);
$tabs = ['header' => ['bi-filetype-js', 'javascript'], 'style' => ['bi-filetype-css', 'css'], 'include' => ['bi-code-slash', 'htmlmixed'],
	'gjs' => ['bi-filetype-js', 'javascript'], 'gcss' => ['bi-filetype-css', 'css']];
$cols += ['gjs' => 'custom_js', 'gcss' => 'custom_css'];                // the global tabs are always there
$values += $gValues;
$scope = NuCustomCode::get()['scope'];
$tplData = NuCodeTemplates::forPage();

nu_layout_start(t('scode_title'), 'home', ['container' => 'nuContainer', 'head' => '<link rel="stylesheet" href="' . nu_asset(NU_CODEMIRROR . '/codemirror.css') . '">']);
?>
<form method="post" action="<?= h(nu_url('globalcode')) ?>" id="scodeForm" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="tab" id="scodeTab" value="">
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-code-square me-2"></i><?= h(t('scode_title')) ?></h1>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?>
			<?php if ($canChange): ?>
				<button class="btn nuBtnAct nuBtnSave" type="submit" data-nu-save id="scodeSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
			<?php endif; ?>
		</div>
	</div>
	<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('scode_warning')) ?></div>
	<?php if ($row === null): ?><div class="alert alert-secondary small py-2"><i class="bi bi-info-circle me-1"></i><?= h(t('scode_missing')) ?></div><?php endif; ?>
	<?php // two groups (0.8.523): Form pages = nuBuilder's Setup fields, All pages = Global JS & CSS
	$scTab = (string)($_GET['tab'] ?? '');                      // the tab the server opens (0.8.451): ?tab=, else the first one
	if (!isset($cols[$scTab], $tabs[$scTab])) $scTab = (string)(array_values(array_filter(array_keys($tabs), static fn($k) => isset($cols[$k])))[0] ?? '');
	$groups = ['scode_group_forms' => ['header', 'style', 'include'], 'scode_group_all' => ['gjs', 'gcss']]; ?>
	<ul class="nav nav-tabs mb-3 scodeTabs" id="scodeTabs" role="tablist" data-nu-hash-tabs data-nu-tab-restore>
		<?php foreach ($groups as $gl => $keys): if (!array_filter($keys, static fn($k) => isset($cols[$k]))) continue; ?>
			<li class="nav-item scodeGroup" role="presentation"><span class="scodeGroupLabel"><?= h(t($gl)) ?></span></li>
			<?php foreach ($keys as $k): if (!isset($cols[$k])) continue; [$icon] = $tabs[$k]; ?>
				<li class="nav-item" role="presentation"><button class="nav-link<?= $k === $scTab ? ' active' : '' ?>" type="button" data-bs-toggle="tab" data-bs-target="#scodePane-<?= $k ?>"
					data-nu-tab="<?= $k ?>" role="tab" id="scodeTabBtn-<?= $k ?>" aria-controls="scodePane-<?= $k ?>" aria-selected="<?= $k === $scTab ? 'true' : 'false' ?>"><i class="bi <?= $icon ?> me-1"></i><?= h(t('scode_tab_' . $k)) ?></button></li>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</ul>
	<div class="tab-content" data-nu-tab-restore>
		<?php foreach ($tabs as $k => [, $mode]): if (!isset($cols[$k])) continue; $global = $k === 'gjs' || $k === 'gcss'; ?>
			<div class="tab-pane fade<?= $k === $scTab ? ' show active' : '' ?>" id="scodePane-<?= $k ?>" role="tabpanel" aria-labelledby="scodeTabBtn-<?= $k ?>" tabindex="0">
				<div class="d-flex flex-wrap align-items-start gap-2 mb-2">
					<p class="small text-body-secondary mb-0 scodeHint"><?= h(t('scode_hint_' . $k)) ?><?php if (!$global): $nbTip = t('scode_nb_field', ['header' => 'Header', 'style' => 'Style', 'include' => 'Include'][$k], NU_SETUP_CODE[$k] ?? $k); // nuBuilder's name of the field (0.8.525) ?>
						<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h($nbTip) ?>" title="<?= h($nbTip) ?>"><i class="bi bi-info-circle"></i></span><?php endif; ?></p>
					<div class="w-100" aria-hidden="true"></div>
					<span class="scodeTools d-flex gap-2" data-for="scode-<?= $k ?>"></span><?php // hint on its own line, the toolbar below it on the left, right above the editor (0.8.592) ?><?php // editor toolbar (0.8.554): undo / redo, search, comment, format, full screen – nucodetools.js ?>
					<?php if ($canChange && !empty($tplData['tabs'][$k])): // templates (0.8.524, core/nucodetemplates.php): insert at the cursor or preview ?>
						<div class="dropdown scodeTpl" data-tab="<?= $k ?>">
							<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-magic me-1"></i><?= h(t('ctpl_menu')) ?></button>
							<ul class="dropdown-menu dropdown-menu-end scodeTplMenu"></ul>
						</div>
					<?php endif; ?>
				</div>
				<?php if ($global): ?>
					<?php if ($k === 'gjs' && ($clash = NuCustomCode::hookClashes((string)$values['gjs']))): ?>
						<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('scode_hook_clash', implode(', ', $clash))) ?></div>
					<?php endif; ?>
					<?php // where it runs – the same three switches on both global tabs, kept in step by nuglobalcode.js (only the first is sent) ?>
					<fieldset class="scodeScope mb-2 d-flex flex-wrap align-items-center gap-3 small"<?= $canChange ? '' : ' disabled' ?>>
						<legend class="visually-hidden"><?= h(t('scode_scope')) ?></legend>
						<span class="fw-semibold"><?= h(t('scode_scope')) ?>:</span>
						<?php foreach (array_keys(NuCustomCode::SCOPES) as $sc): $sid = 'scodeScope-' . $k . '-' . $sc; ?>
							<span class="form-check form-switch mb-0"><input class="form-check-input scodeScopeSw" type="checkbox" role="switch" id="<?= $sid ?>" data-scope="<?= $sc ?>"
								<?= $k === 'gjs' ? 'name="scope[' . $sc . ']" value="1"' : '' ?><?= !empty($scope[$sc]) ? ' checked' : '' ?>><label class="form-check-label" for="<?= $sid ?>"><?= h(t('scode_scope_' . $sc)) ?></label></span>
						<?php endforeach; ?>
						<?php if ($k === 'gjs'): ?><input type="hidden" name="scope_sent" value="1"><?php endif; ?>
						<span class="text-body-secondary"><?= h(t('scode_scope_never')) ?></span>
					</fieldset>
				<?php endif; ?>
				<input type="hidden" name="hash[<?= $k ?>]" value="<?= h($hashOf($global ? (string)$gValues[$k] : (string)($row[$cols[$k]] === null ? '' : str_replace("\r\n", "\n", (string)$row[$cols[$k]])))) ?>">
				<div class="card shadow-sm"><textarea class="form-control font-monospace border-0 scodeCode" name="code[<?= $k ?>]" id="scode-<?= $k ?>" rows="24" spellcheck="false"
					data-mode="<?= $mode ?>" aria-label="<?= h(t('scode_tab_' . $k)) ?>"<?= $canChange ? '' : ' readonly' ?>><?= h((string)$values[$k]) ?></textarea></div>
			</div>
		<?php endforeach; ?>
	</div>
</form>
<?php if ($canChange): // templates: data for assets/js/nuglobalcode.js and the preview dialogue (0.8.524) ?>
<div id="scodeTplData" hidden data-json="<?= h((string)json_encode($tplData, JSON_UNESCAPED_UNICODE)) ?>"
	data-t="<?= h((string)json_encode(['preview' => t('ctpl_preview'), 'copied' => t('ctpl_copied')], JSON_UNESCAPED_UNICODE)) ?>"></div>
<div class="modal fade" id="scodeTplDlg" tabindex="-1" aria-labelledby="scodeTplDlgTitle" aria-hidden="true">
	<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header"><h2 class="modal-title h5" id="scodeTplDlgTitle"><i class="bi bi-magic me-2"></i><?= h(t('ctpl_menu')) ?> – <span id="scodeTplDlgTab"></span></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
		<div class="modal-body p-0"><div class="row g-0 scodeTplBody">
			<div class="col-md-4 border-end"><div class="list-group list-group-flush scodeTplList" id="scodeTplList" role="listbox" aria-label="<?= h(t('ctpl_menu')) ?>"></div></div>
			<div class="col-md-8 p-3"><h3 class="h6 mb-1" id="scodeTplName"></h3><p class="small text-body-secondary" id="scodeTplDesc"></p>
				<pre class="scodeTplCode mb-0"><code id="scodeTplCode"></code></pre></div>
		</div></div>
		<div class="modal-footer">
			<button type="button" class="btn btn-outline-secondary me-auto" id="scodeTplCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('ctpl_copy')) ?></button>
			<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
			<button type="button" class="btn btn-primary" id="scodeTplInsert"><i class="bi bi-box-arrow-in-down me-1"></i><?= h(t('ctpl_insert')) ?></button>
		</div>
	</div></div>
</div>
<?php endif; ?>
<?php
nu_layout_end('<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-bundle.js') . '"></script><script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-addons.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nu/nucodetools.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nuglobalcode.js') . '"></script>');
