<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Admin tools → Setup code (0.8.444): nuBuilder's Setup → Header (JavaScript), Style (CSS) and Include (<link> / <script> tags) – the same
 * columns of zzzzsys_setup, so nuBuilder and nuBuilder Next stay in step. Used on every form page (pages/nuform.php, NuIncludes::setupTags).
 * POST action save: only changed fields are written; a field someone else changed since the page was opened is refused (hash of the
 * loaded values). Tabs in the address (#header, #style, #include – nuapp.js data-nu-hash-tabs); editors: assets/js/nusetupcode.js.
 */

NuAdmin::require('setupcode');
NuSecurity::requireRecentAuth(nu_url('setupcode'));   // code for every user's form pages: password again after 15 minutes

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

if (nu_is_post() && nu_post('action') === 'save' && $row !== null) {
	NuAdmin::require('setupcode', 'change');
	$set = [];
	$conflict = [];
	foreach ($cols as $k => $c) {
		if (!isset($_POST['code'][$k]) || !is_string($_POST['code'][$k])) continue;
		$new = str_replace("\r\n", "\n", $_POST['code'][$k]);
		if ($new === $values[$k]) continue;
		if ((string)($_POST['hash'][$k] ?? '') !== $hashOf($values[$k])) { $conflict[] = $k; continue; }   // changed by someone else meanwhile
		$set[$c] = $new;
	}
	if ($conflict) {
		nu_flash('danger', t('scode_conflict', implode(', ', array_map(static fn($k) => t('scode_tab_' . $k), $conflict))));
		$values = array_merge($values, array_intersect_key(array_map(static fn($v) => str_replace("\r\n", "\n", (string)$v), (array)$_POST['code']), $values));
	} elseif (!$set) {
		nu_flash('info', t('scode_unchanged'));
		$st = preg_replace('/[^a-z]/', '', (string)nu_post('tab'));
		nu_redirect(nu_url('setupcode', $st !== '' ? ['tab' => $st] : []) . ($st !== '' ? '#' . $st : ''));   // ?tab=: the server opens it (0.8.451)
	} else {
		$db->update('zzzzsys_setup', $set, 'zzzzsys_setup_id', (string)$row['zzzzsys_setup_id']);
		$names = array_map(static fn($c) => t('scode_tab_' . array_search($c, NU_SETUP_CODE, true)), array_keys($set));
		NuLog::write('setup_code_saved', 'zzzzsys_setup', (string)$row['zzzzsys_setup_id'],
			['fields' => array_keys($set), 'chars' => array_map(static fn($v) => mb_strlen($v), $set)]);
		nu_flash('success', t('scode_saved', implode(', ', $names)));
		$st = preg_replace('/[^a-z]/', '', (string)nu_post('tab'));
		nu_redirect(nu_url('setupcode', $st !== '' ? ['tab' => $st] : []) . ($st !== '' ? '#' . $st : ''));   // ?tab=: the server opens it (0.8.451)
	}
}

$canChange = NuAdmin::can('setupcode', 'change') && empty(NuAuth::user()['demo']);
$tabs = ['header' => ['bi-filetype-js', 'javascript'], 'style' => ['bi-filetype-css', 'css'], 'include' => ['bi-code-slash', 'htmlmixed']];

nu_layout_start(t('scode_title'), 'home', ['container' => 'container-xl', 'head' => '<link rel="stylesheet" href="' . nu_asset(NU_CODEMIRROR . '/codemirror.css') . '">']);
?>
<form method="post" action="<?= h(nu_url('setupcode')) ?>" id="scodeForm" data-nu-guard>
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="tab" id="scodeTab" value="">
	<div class="nuTitleBar mb-3">
		<h1 class="h4 mb-0"><i class="bi bi-code-square me-2"></i><?= h(t('scode_title')) ?></h1>
		<div class="nuTitleSub text-body-secondary small"><?= h(t('scode_sub')) ?></div>
		<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?>
			<?php if ($canChange && $row !== null): ?>
				<button class="btn nuBtnAct nuBtnSave" type="submit" data-nu-save id="scodeSave"><i class="bi bi-floppy me-1"></i><?= h(t('save')) ?></button>
			<?php endif; ?>
		</div>
	</div>
	<?php if ($row === null): ?>
		<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('scode_missing')) ?></div>
	<?php else: ?>
		<div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('scode_warning')) ?></div>
		<ul class="nav nav-tabs mb-3" id="scodeTabs" role="tablist" data-nu-hash-tabs data-nu-tab-restore>
			<?php $scTab = (string)($_GET['tab'] ?? '');                      // the tab the server opens (0.8.451): ?tab=, else the first one
			if (!isset($cols[$scTab], $tabs[$scTab])) $scTab = (string)(array_values(array_filter(array_keys($tabs), static fn($k) => isset($cols[$k])))[0] ?? ''); ?>
			<?php foreach ($tabs as $k => [$icon]): if (!isset($cols[$k])) continue; ?>
				<li class="nav-item" role="presentation"><button class="nav-link<?= $k === $scTab ? ' active' : '' ?>" type="button" data-bs-toggle="tab" data-bs-target="#scodePane-<?= $k ?>"
					data-nu-tab="<?= $k ?>" role="tab" id="scodeTabBtn-<?= $k ?>" aria-controls="scodePane-<?= $k ?>" aria-selected="<?= $k === $scTab ? 'true' : 'false' ?>"><i class="bi <?= $icon ?> me-1"></i><?= h(t('scode_tab_' . $k)) ?></button></li>
			<?php endforeach; ?>
		</ul>
		<div class="tab-content" data-nu-tab-restore>
			<?php foreach ($tabs as $k => [, $mode]): if (!isset($cols[$k])) continue; ?>
				<div class="tab-pane fade<?= $k === $scTab ? ' show active' : '' ?>" id="scodePane-<?= $k ?>" role="tabpanel" aria-labelledby="scodeTabBtn-<?= $k ?>" tabindex="0">
					<p class="small text-body-secondary mb-2"><?= h(t('scode_hint_' . $k)) ?></p>
					<input type="hidden" name="hash[<?= $k ?>]" value="<?= h($hashOf((string)($row[$cols[$k]] === null ? '' : str_replace("\r\n", "\n", (string)$row[$cols[$k]])))) ?>">
					<div class="card shadow-sm"><textarea class="form-control font-monospace border-0 scodeCode" name="code[<?= $k ?>]" id="scode-<?= $k ?>" rows="24" spellcheck="false"
						data-mode="<?= $mode ?>" aria-label="<?= h(t('scode_tab_' . $k)) ?>"<?= $canChange ? '' : ' readonly' ?>><?= h($values[$k]) ?></textarea></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</form>
<?php
nu_layout_end('<script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-bundle.js') . '"></script><script src="' . nu_asset(NU_CODEMIRROR . '/codemirror-addons.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nusetupcode.js') . '"></script>');
