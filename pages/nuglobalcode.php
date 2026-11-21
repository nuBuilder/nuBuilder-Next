<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Admin tools → Custom code (once "Setup code"). Two layers:
 *   Form pages (Form JavaScript / Form CSS / Form includes): nuBuilder's Setup → Header, Style and Include (<link> / <script> tags) – the same
 * columns of zzzzsys_setup, so nuBuilder and nuBuilder Next stay in step. Used on every form page (pages/nuform.php, NuIncludes::setupTags).
 * POST action save: only changed fields are written; a field someone else changed since the page was opened is refused (hash of the
 * loaded values). Tabs in the address (#header, #style, #include – nuapp.js data-nu-hash-tabs); editors: assets/js/nuglobalcode.js.
 *   All pages: Global JS (#gjs) and Global CSS (#gcss) + where they run (scope) – NuCustomCode, nuBuilder Next's own settings.
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

// Global JS & CSS: nuBuilder Next's own settings – also without zzzzsys_setup
$global = NuCustomCode::get();
$gValues = ['gjs' => str_replace("\r\n", "\n", $global['js']), 'gcss' => str_replace("\r\n", "\n", $global['css'])];

['gValues' => $gValues, 'k' => $k, 'hashOf' => $hashOf, 'cols' => $cols, 'values' => $values, 'row' => $row]
	= NuGlobalcodePage::post(get_defined_vars());   // the POST actions (code review R7, nuglobalcodepage.php)

$canChange = NuAdmin::can('globalcode', 'change') && empty(NuAuth::user()['demo']);
$tabs = ['header' => ['bi-filetype-js', 'javascript'], 'style' => ['bi-filetype-css', 'css'], 'include' => ['bi-code-slash', 'htmlmixed'],
	'gjs' => ['bi-filetype-js', 'javascript'], 'gcss' => ['bi-filetype-css', 'css']];
$cols += ['gjs' => 'custom_js', 'gcss' => 'custom_css'];                // the global tabs are always there
$values += $gValues;
$scope = NuCustomCode::get()['scope'];
$tplData = NuCodeTemplates::forPage();

nu_layout_start(t('scode_title'), 'home', ['container' => 'nuContainer', 'settings' => 'code', 'head' => '<link rel="stylesheet" href="' . nu_asset(NU_CODEMIRROR . '/codemirror.css') . '">']);
?>
<form method="post" action="<?= h(nu_url('globalcode')) ?>" id="scodeForm" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="tab" id="scodeTab" value="">
	<?= nu_page_header(t('scode_title'), ['icon' => 'bi-code-square',
		// the warning can be hidden (localStorage nunext-scode-warn-hidden, nuglobalcode.js) – then this (i) shows it again
		'title_html' => '<button type="button" class="btn btn-link p-0 ms-1 nuInfoTip scodeWarnShow" id="scodeWarnShow" hidden aria-controls="scodeWarn" aria-expanded="false" title="'
			. h(t('scode_warn_show')) . '" aria-label="' . h(t('scode_warn_show')) . '"><i class="bi bi-info-circle" aria-hidden="true"></i></button>',
		'buttons' => $canChange ? '<button class="btn nuBtnAct nuBtnSave" type="submit" data-nu-save id="scodeSave"><i class="bi bi-floppy me-1"></i>' . h(t('save')) . '</button>' : '']) ?>
	<div class="alert alert-warning alert-dismissible small py-2 scodeWarn"
		id="scodeWarn"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('scode_warning')) ?><button type="button" class="btn-close" id="scodeWarnHide"
		title="<?= h(t('scode_warn_hide')) ?>" aria-label="<?= h(t('scode_warn_hide')) ?>"></button></div>
	<?php if ($row === null): ?><div class="alert alert-secondary small py-2"><i class="bi bi-info-circle me-1"></i><?= h(t('scode_missing')) ?></div><?php endif; ?>
	<?php // two groups: Form pages = nuBuilder's Setup fields, All pages = Global JS & CSS
	$scTab = (string)($_GET['tab'] ?? '');                      // the tab the server opens: ?tab=, else the first one
	if (!isset($cols[$scTab], $tabs[$scTab])) $scTab = (string)(array_values(array_filter(array_keys($tabs), static fn($k) => isset($cols[$k])))[0] ?? '');
	// a switch Form pages | All pages, then the tabs of that group only (short tab names, nothing repeated); nuglobalcode.js switches
	$groups = ['forms' => ['header', 'style', 'include'], 'all' => ['gjs', 'gcss']];
	$short = ['header' => 'scode_short_js', 'style' => 'scode_short_css', 'include' => 'scode_short_inc', 'gjs' => 'scode_short_js', 'gcss' => 'scode_short_css'];
	$groups = array_filter(array_map(static fn($keys) => array_values(array_filter($keys, static fn($k) => isset($cols[$k]))), $groups));
	$scGroup = in_array($scTab, $groups['all'] ?? [], true) ? 'all' : (isset($groups['forms']) ? 'forms' : 'all'); ?>
	<div class="scodeNav d-flex flex-wrap align-items-center column-gap-3 row-gap-2 mb-3" data-nu-tab-restore>
		<?php if (count($groups) > 1): ?>
		<div class="btn-group nuSeg scodeSeg" role="group" id="scodeSeg" aria-label="<?= h(t('scode_group_pick')) ?>">
			<?php foreach ($groups as $g => $_): ?>
				<button type="button" class="btn btn-outline-secondary<?= $g === $scGroup ? ' active' : '' ?>" data-scode-group="<?= $g ?>"
					aria-pressed="<?= $g === $scGroup ? 'true' : 'false' ?>"><?= h(t('scode_group_' . $g)) ?></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<ul class="nav nav-tabs scodeTabs flex-grow-1" id="scodeTabs" role="tablist" data-nu-hash-tabs>
			<?php foreach ($groups as $g => $keys): foreach ($keys as $k): [$icon] = $tabs[$k]; ?>
				<li class="nav-item" role="presentation"
					data-scode-group="<?= $g ?>"<?= $g === $scGroup ? '' : ' hidden' ?>><button class="nav-link<?= $k === $scTab ? ' active' : '' ?>" type="button"
					data-bs-toggle="tab" data-bs-target="#scodePane-<?= $k ?>"
					data-nu-tab="<?= $k ?>" role="tab" id="scodeTabBtn-<?= $k ?>" aria-controls="scodePane-<?= $k ?>"
						aria-selected="<?= $k === $scTab ? 'true' : 'false' ?>" title="<?= h(t('scode_tab_' . $k)) ?>"><i class="bi <?= $icon ?> me-1"
						aria-hidden="true"></i><?= h(t($short[$k])) ?></button></li>
			<?php endforeach; endforeach; ?>
		</ul>
	</div>
	<div class="tab-content" data-nu-tab-restore>
		<?php foreach ($tabs as $k => [, $mode]): if (!isset($cols[$k])) continue; $global = $k === 'gjs' || $k === 'gcss'; ?>
			<div class="tab-pane fade<?= $k === $scTab ? ' show active' : '' ?>" id="scodePane-<?= $k ?>" role="tabpanel" aria-labelledby="scodeTabBtn-<?= $k ?>" tabindex="0">
				<div class="d-flex flex-wrap align-items-start gap-2 mb-2">
					<p class="small text-body-secondary mb-0 scodeHint nuCodeFullHide"><?= h(t('scode_hint_' . $k)) ?><?php if (!$global): $nbTip = t('scode_nb_field', ['header' => 'Header', 'style' => 'Style', 'include' => 'Include'][$k], NU_SETUP_CODE[$k] ?? $k); // nuBuilder's name of the field ?>
						<?= nu_info_tip($nbTip) ?><?php endif; ?></p>
					<div class="w-100 nuCodeFullHide" aria-hidden="true"></div>
					<span class="scodeTools d-flex gap-2"
						data-for="scode-<?= $k ?>"></span><?php // hint on its own line, the toolbar below it on the left, right above the editor ?><?php // editor toolbar: undo / redo, search, comment, format, full screen – nucodetools.js ?>
					<?php if ($canChange && !empty($tplData['tabs'][$k])): // templates (core/nucodetemplates.php): insert at the cursor or preview ?>
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
					<fieldset class="scodeScope nuCodeFullHide mb-2 d-flex flex-wrap align-items-center gap-3 small"<?= $canChange ? '' : ' disabled' ?>>
						<legend class="visually-hidden"><?= h(t('scode_scope')) ?></legend>
						<span class="fw-semibold"><?= h(t('scode_scope')) ?>:</span>
						<?php foreach (array_keys(NuCustomCode::SCOPES) as $sc): $sid = 'scodeScope-' . $k . '-' . $sc; ?>
							<span class="form-check form-switch mb-0"><input class="form-check-input scodeScopeSw" type="checkbox" role="switch" id="<?= $sid ?>" data-scope="<?= $sc ?>"
								<?= $k === 'gjs' ? 'name="scope[' . $sc . ']" value="1"' : '' ?><?= !empty($scope[$sc]) ? ' checked' : '' ?>><label class="form-check-label"
									for="<?= $sid ?>" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide"
									title="<?= h(t('scode_scope_' . $sc . '_tip')) ?>"><?= h(t('scode_scope_' . $sc)) ?></label></span>
						<?php endforeach; ?>
						<?php if ($k === 'gjs'): ?><input type="hidden" name="scope_sent" value="1"><?php endif; ?>
						<span class="text-body-secondary"><?= h(t('scode_scope_never')) ?></span>
					</fieldset>
				<?php endif; ?>
				<input type="hidden" name="hash[<?= $k ?>]"
					value="<?= h($hashOf($global ? (string)$gValues[$k] : (string)($row[$cols[$k]] === null ? '' : str_replace("\r\n", "\n", (string)$row[$cols[$k]])))) ?>">
				<div class="card shadow-sm"><textarea class="form-control font-monospace border-0 scodeCode" name="code[<?= $k ?>]" id="scode-<?= $k ?>" rows="24" spellcheck="false"
					data-mode="<?= $mode ?>" aria-label="<?= h(t('scode_tab_' . $k)) ?>"<?= $canChange ? '' : ' readonly' ?>><?= h((string)$values[$k]) ?></textarea></div>
			</div>
		<?php endforeach; ?>
	</div>
</form>
<?php if ($canChange): // templates: data for assets/js/nuglobalcode.js and the preview dialogue ?>
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
nu_layout_end('<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-bundle.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nu/nucodetools.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nuglobalcode.js') . '"></script>');
