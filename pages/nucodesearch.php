<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Code search (0.8.1172, core/nucodesearch.php): GET = the search form and the results (?q=…&m=&x=&w=&c=&r=&lang[]=&place[]=&f[]=&grp=&dep=&team=&prj=
 * &sys=&drafts=&pages=&go=1), ?export=csv|xlsx = the hits as a file; POST action save / delete_saved = "My searches" (zzzzsys_nunext_list_view,
 * list_key codesearch, per user). Read only – nothing typed here runs. The globeadmin or the admin right Code search.
 */
if (!NuCodeSearch::allowed()) {
	if (nu_is_post()) { http_response_code(403); exit; }
	nu_fatal(t('err_forbidden'), '', true);
}
nu_time_limit(120);

$s = NuCodeSearch::request($_GET);
$selfQ = NuCodeSearch::query($s);

if (nu_is_post()) {
	$action = nu_post('action');
	if ($action === 'save') {
		parse_str(nu_post('search'), $sq);
		$err = NuCodeSearch::save(nu_post('name'), NuCodeSearch::request($sq));
		nu_flash($err === '' ? 'success' : 'danger', $err === '' ? t('cs_saved_ok') : $err);
		nu_redirect(nu_url('codesearch', $sq));
	}
	if ($action === 'delete_saved') NuCodeSearch::delete(nu_post('id'));
	// search and replace (0.8.1174): the globeadmin, password confirmed within the last minutes
	if ($action === 'replace' || $action === 'undo') {
		parse_str(nu_post('search'), $sq);
		$back = nu_url('codesearch', $sq ?: $selfQ);
		if (!NuCodeSearch::canReplace()) { http_response_code(403); exit; }
		if (!NuSecurity::recentAuth()) NuSecurity::requireRecentAuth($back);
		if ($action === 'undo') {
			$r = NuCodeSearch::undo(nu_post('id'));
			nu_flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? t('cs_rp_undone', $r['done'], $r['skipped']) : $r['error']);
			nu_redirect($back);
		}
		$ticks = is_array($_POST['t'] ?? null) ? $_POST['t'] : [];
		$hashes = is_array($_POST['h'] ?? null) ? array_map('strval', $_POST['h']) : [];
		$r = NuCodeSearch::apply(NuCodeSearch::request($sq), (string)($_POST['rp'] ?? ''), $ticks, $hashes);
		if ($r['error'] !== '') nu_flash('danger', $r['error']);
		elseif ($r['parts']) nu_flash('success', t('cs_rp_done', $r['lines'], $r['parts']));
		if ($r['skipped']) nu_flash('warning', t('cs_rp_skipped') . ' ' . implode(' · ', array_slice($r['skipped'], 0, 20)));
		unset($sq['rmode']);
		nu_redirect(nu_url('codesearch', $sq));
	}
	nu_redirect(nu_url('codesearch', $selfQ));
}

// replace mode: ?rmode=1&rp=<text> – a preview with ticks (globeadmin, recent password)
$canReplace = NuCodeSearch::canReplace();
$rmode = $canReplace && ($_GET['rmode'] ?? '') === '1';
$rp = mb_substr((string)($_GET['rp'] ?? ''), 0, 1000);
if ($rmode) NuSecurity::requireRecentAuth(nu_url('codesearch', $selfQ + ['rmode' => '1', 'rp' => $rp]));
[$rpPattern, $rpRepl, $rpErr] = $rmode ? NuCodeSearch::replacePattern($s, $rp) : ['', '', ''];
$drafts = $rmode ? NuCodeSearch::draftForms() : [];

$res = $s['q'] !== '' || $s['preset'] !== '' ? NuCodeSearch::run($s) : null;

// ---- export of the hits
$fmt = (string)($_GET['export'] ?? '');
if ($res && in_array($fmt, ['csv', 'xlsx'], true) && $res['error'] === '') {
	session_write_close();
	$head = [t('cs_h_item'), t('cs_h_part'), t('cs_f_place'), t('cs_f_lang'), t('cs_h_line'), 'Code', 'URL'];
	$rows = NuCodeSearch::rows($res);
	$name = 'code-search-' . date('Ymd-His');
	if ($fmt === 'xlsx') {
		$x = new NuXlsx(t('cs_title'));
		$x->sheet(t('cs_title'))->header($head)->rows($rows);
		$x->download($name . '.xlsx');
	}
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $name . '.csv"');
	header('X-Content-Type-Options: nosniff');
	$o = fopen('php://output', 'w');
	fwrite($o, "\xEF\xBB\xBF");
	fputcsv($o, $head, ';', '"', '');
	foreach ($rows as $r) fputcsv($o, array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $r), ';', '"', '');
	exit;
}

// ---- choices of the filters
$db = nu_db();
$forms = [];
foreach ($db->all('SELECT zzzzsys_form_id AS id, sfo_code AS code, sfo_description AS d, sfo_group AS g FROM zzzzsys_form ORDER BY sfo_code') as $f) {
	if (!$s['sys'] && str_starts_with((string)$f['id'], 'nu') && !in_array((string)$f['id'], $s['f'], true)) continue;
	$forms[(string)$f['id']] = [(string)$f['code'], trim(strip_tags((string)$f['d']))];
}
$groups = array_values(array_unique(array_filter(array_map(static fn($r) => trim((string)$r['g']), $db->all("SELECT DISTINCT sfo_group AS g FROM zzzzsys_form WHERE sfo_group IS NOT NULL AND sfo_group <> ''")))));
sort($groups);
$tagVals = NuFormMeta::tagValues();
$projects = class_exists('NuProject') && NuProject::canView() ? NuProject::choices() : [];
$quick = NuCodeSearch::quick();
$saved = NuCodeSearch::saved();
$filterCount = count($s['lang']) + count($s['place']) + count($s['f']) + ($s['grp'] !== '' ? 1 : 0) + ($s['dep'] !== '' ? 1 : 0) + ($s['team'] !== '' ? 1 : 0) + ($s['prj'] !== '' ? 1 : 0)
	+ ($s['sys'] ? 1 : 0) + ($s['drafts'] ? 0 : 1) + ($s['pages'] ? 0 : 1);
$langNames = ['php' => 'PHP', 'js' => 'JavaScript', 'sql' => 'SQL', 'css' => 'CSS', 'html' => 'HTML', 'text' => t('cs_lang_text')];
$chk = static fn(bool $on): string => $on ? ' checked' : '';
$sel = static fn(bool $on): string => $on ? ' selected' : '';

nu_layout_start(t('cs_title'), 'home', ['head' => nu_ts_head()]);
echo nu_page_header(t('cs_title'), ['icon' => 'bi-search', 'tip' => t('cs_hint'),
	'buttons' => '<div class="dropdown"><button type="button" class="btn nuBtnAct dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" id="csSavedBtn"><i class="bi bi-bookmark me-1"></i>' . h(t('cs_saved')) . '</button>'
		. '<ul class="dropdown-menu dropdown-menu-end csSavedMenu" id="csSavedMenu">'
		. ($saved ? implode('', array_map(static fn($r) => '<li class="d-flex align-items-center"><a class="dropdown-item flex-grow-1" href="' . h('index.php?p=codesearch&' . $r['q']) . '">' . h($r['name']) . '</a>'
			. '<form method="post" class="me-2"><input type="hidden" name="_csrf" value="' . h(nu_csrf_token()) . '"><input type="hidden" name="action" value="delete_saved"><input type="hidden" name="id" value="' . h($r['id']) . '">'
			. '<button type="submit" class="btn btn-sm btn-link text-danger p-0 csSavedDel" title="' . h(t('delete')) . '" aria-label="' . h(t('delete')) . '"><i class="bi bi-x-lg"></i></button></form></li>', $saved))
			: '<li><span class="dropdown-item-text small text-body-secondary">' . h(t('cs_saved_none')) . '</span></li>')
		. ($s['q'] !== '' || $s['preset'] !== '' ? '<li><hr class="dropdown-divider"></li><li><button type="button" class="dropdown-item" id="csSaveOpen"><i class="bi bi-bookmark-plus me-2"></i>' . h(t('cs_save')) . '</button></li>' : '')
		. '</ul></div>']);
?>
<form method="get" action="index.php" class="card shadow-sm mb-3 csForm" id="csForm" role="search">
	<input type="hidden" name="p" value="codesearch"><input type="hidden" name="go" value="1">
	<?php if ($s['preset'] !== ''): ?><input type="hidden" name="preset" id="csPreset" value="<?= h($s['preset']) ?>"><?php endif; ?>
	<div class="card-body">
		<div class="d-flex flex-wrap gap-2 align-items-center">
			<div class="input-group csQ">
				<span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
				<input type="search" class="form-control font-monospace" name="q" id="csQ" value="<?= h($s['q']) ?>" placeholder="<?= h(t('cs_q_ph')) ?>" aria-label="<?= h(t('cs_title')) ?>" autocomplete="off" spellcheck="false" autofocus>
				<button class="btn btn-primary" type="submit" id="csGo"><?= h(t('cs_search')) ?></button>
			</div>
			<div class="btn-group" role="group" aria-label="<?= h(t('cs_mode_phrase')) ?>">
				<?php foreach (['phrase', 'all', 'any'] as $m): ?>
				<input type="radio" class="btn-check" name="m" id="csM_<?= $m ?>" value="<?= $m ?>"<?= $chk($s['m'] === $m) ?>><label class="btn btn-outline-secondary btn-sm" for="csM_<?= $m ?>"><?= h(t('cs_mode_' . $m)) ?></label>
				<?php endforeach; ?>
			</div>
			<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('cs_mode_tip')) ?>" title="<?= h(t('cs_mode_tip')) ?>"><i class="bi bi-info-circle"></i></span>
		</div>
		<?php if ($s['preset'] === 'missing'): ?>
		<div class="mt-2 csPresetRow" id="csPresetChip"><span class="badge rounded-pill text-bg-secondary d-inline-flex align-items-center gap-2 py-2 px-3 csPresetChip">
			<i class="bi bi-exclamation-diamond" aria-hidden="true"></i><?= h(t('cs_quick_missing')) ?>
			<a class="text-reset" href="<?= h(nu_url('codesearch')) ?>" title="<?= h(t('close')) ?>" aria-label="<?= h(t('close')) ?>"><i class="bi bi-x-lg"></i></a></span></div>
		<?php endif; ?>
		<div class="d-flex flex-wrap gap-3 align-items-center mt-2">
			<div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="w" value="1" id="csW"<?= $chk($s['w']) ?>><label class="form-check-label small" for="csW"><?= h(t('cs_word')) ?></label></div>
			<div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="c" value="1" id="csC"<?= $chk($s['c']) ?>><label class="form-check-label small" for="csC"><?= h(t('cs_case')) ?></label></div>
			<div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="r" value="1" id="csR"<?= $chk($s['r']) ?>><label class="form-check-label small" for="csR"><?= h(t('cs_regex')) ?></label></div>
			<div class="d-flex align-items-center gap-1 csWithout"><label class="small text-body-secondary" for="csX"><?= h(t('cs_without')) ?></label>
				<input type="text" class="form-control form-control-sm font-monospace" name="x" id="csX" value="<?= h($s['x']) ?>" autocomplete="off" spellcheck="false">
				<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" aria-label="<?= h(t('cs_without_tip')) ?>" title="<?= h(t('cs_without_tip')) ?>"><i class="bi bi-info-circle"></i></span></div>
			<?php if ($canReplace): ?><button class="btn btn-sm btn-link px-0 ms-auto csRpBtn" type="button" data-bs-toggle="collapse" data-bs-target="#csRpRow" aria-expanded="<?= $rmode ? 'true' : 'false' ?>" aria-controls="csRpRow" id="csRpToggle"><i class="bi bi-arrow-left-right me-1"></i><?= h(t('cs_replace')) ?></button><?php endif; ?>
			<button class="btn btn-sm btn-link px-0<?= $canReplace ? '' : ' ms-auto' ?> csFiltersBtn" type="button" data-bs-toggle="collapse" data-bs-target="#csFilters" aria-expanded="<?= $filterCount ? 'true' : 'false' ?>" aria-controls="csFilters">
				<i class="bi bi-funnel me-1"></i><?= h(t('cs_filters')) ?><?= $filterCount ? ' <span class="badge text-bg-primary" id="csFilterCount">' . $filterCount . '</span>' : '' ?></button>
		</div>
		<?php if ($canReplace): ?>
		<div class="collapse<?= $rmode ? ' show' : '' ?>" id="csRpRow">
			<div class="d-flex flex-wrap gap-2 align-items-center mt-2 csRp">
				<label class="small text-body-secondary" for="csRp"><?= h(t('cs_rp_label')) ?></label>
				<input type="text" class="form-control form-control-sm font-monospace csRpInput" name="rp" id="csRp" value="<?= h($rp) ?>" autocomplete="off" spellcheck="false"<?= $rmode ? '' : ' disabled' ?>>
				<input type="hidden" name="rmode" value="1" id="csRmode"<?= $rmode ? '' : ' disabled' ?>>
				<button type="submit" class="btn btn-sm btn-outline-danger" id="csRpPreview"><i class="bi bi-eye me-1"></i><?= h(t('cs_rp_preview')) ?></button>
				<span class="nuInfoTip" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="<?= h(t('cs_rp_tip')) ?>" title="<?= h(t('cs_rp_tip')) ?>"><i class="bi bi-info-circle"></i></span>
			</div>
		</div>
		<?php endif; ?>
		<div class="collapse<?= $filterCount ? ' show' : '' ?>" id="csFilters">
			<div class="row g-2 mt-1">
				<div class="col-md-6 col-xl-4"><label class="form-label small mb-1" for="csLang"><?= h(t('cs_f_lang')) ?></label>
					<select class="form-select form-select-sm nuListTomSelect" multiple name="lang[]" id="csLang" data-placeholder="<?= h(t('cs_any')) ?>">
						<?php foreach ($langNames as $k => $l): ?><option value="<?= $k ?>"<?= $sel(in_array($k, $s['lang'], true)) ?>><?= h($l) ?></option><?php endforeach; ?>
					</select></div>
				<div class="col-md-6 col-xl-4"><label class="form-label small mb-1" for="csPlace"><?= h(t('cs_f_place')) ?></label>
					<select class="form-select form-select-sm nuListTomSelect" multiple name="place[]" id="csPlace" data-placeholder="<?= h(t('cs_any')) ?>">
						<?php foreach (NuCodeSearch::PLACES as $k => [$lbl]): ?><option value="<?= $k ?>"<?= $sel(in_array($k, $s['place'], true)) ?>><?= h(t($lbl)) ?></option><?php endforeach; ?>
					</select></div>
				<div class="col-md-12 col-xl-4"><label class="form-label small mb-1" for="csForms"><?= h(t('cs_f_forms')) ?></label>
					<select class="form-select form-select-sm nuListTomSelect" multiple name="f[]" id="csForms" data-placeholder="<?= h(t('cs_any')) ?>">
						<?php foreach ($forms as $id => [$code, $d]): ?><option value="<?= h($id) ?>" data-sub="<?= h($d) ?>"<?= $sel(in_array($id, $s['f'], true)) ?>><?= h($code) ?></option><?php endforeach; ?>
					</select></div>
				<div class="col-6 col-md-3"><label class="form-label small mb-1" for="csGrp"><?= h(t('cs_f_group')) ?></label>
					<select class="form-select form-select-sm" name="grp" id="csGrp"><option value=""><?= h(t('cs_any')) ?></option>
						<?php foreach ($groups as $g): ?><option value="<?= h($g) ?>"<?= $sel($s['grp'] === $g) ?>><?= h($g) ?></option><?php endforeach; ?></select></div>
				<div class="col-6 col-md-3"><label class="form-label small mb-1" for="csDep"><?= h(t('cs_f_dep')) ?></label>
					<select class="form-select form-select-sm" name="dep" id="csDep"><option value=""><?= h(t('cs_any')) ?></option>
						<?php foreach ((array)($tagVals['department'] ?? []) as $g): ?><option value="<?= h($g) ?>"<?= $sel($s['dep'] === $g) ?>><?= h($g) ?></option><?php endforeach; ?></select></div>
				<div class="col-6 col-md-3"><label class="form-label small mb-1" for="csTeam"><?= h(t('cs_f_team')) ?></label>
					<select class="form-select form-select-sm" name="team" id="csTeam"><option value=""><?= h(t('cs_any')) ?></option>
						<?php foreach ((array)($tagVals['team'] ?? []) as $g): ?><option value="<?= h($g) ?>"<?= $sel($s['team'] === $g) ?>><?= h($g) ?></option><?php endforeach; ?></select></div>
				<div class="col-6 col-md-3"><label class="form-label small mb-1" for="csPrj"><?= h(t('cs_f_project')) ?></label>
					<select class="form-select form-select-sm" name="prj" id="csPrj"><option value=""><?= h(t('cs_any')) ?></option>
						<?php foreach ($projects as $id => $n): ?><option value="<?= h((string)$id) ?>"<?= $sel($s['prj'] === (string)$id) ?>><?= h($n) ?></option><?php endforeach; ?></select></div>
			</div>
			<div class="d-flex flex-wrap gap-3 align-items-center mt-2 small">
				<span class="text-body-secondary"><?= h(t('cs_src')) ?>:</span>
				<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" name="drafts" value="1" id="csDrafts"<?= $chk($s['drafts']) ?>><label class="form-check-label" for="csDrafts"><?= h(t('cs_src_drafts')) ?></label></div>
				<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" name="pages" value="1" id="csPages"<?= $chk($s['pages']) ?>><label class="form-check-label" for="csPages"><?= h(t('cs_src_pages')) ?></label></div>
				<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" name="sys" value="1" id="csSys"<?= $chk($s['sys']) ?>><label class="form-check-label" for="csSys"><?= h(t('cs_src_sys')) ?></label></div>
			</div>
		</div>
		<div class="d-flex flex-wrap gap-2 align-items-center mt-3 pt-2 border-top csQuick">
			<span class="small text-body-secondary me-1"><?= h(t('cs_quick')) ?></span>
			<div class="csQuickSel"><select class="form-select form-select-sm nuListTomSelect" id="csQuickFn" data-placeholder="<?= h(t('cs_quick_fn')) ?>" aria-label="<?= h(t('cs_quick_fn')) ?>"><option value=""></option>
				<?php foreach ($quick['fn'] as [$n, $l]): ?><option value="<?= h($n) ?>" data-lang="<?= $l ?>" data-sub="<?= $l === 'php' ? 'PHP' : 'JavaScript' ?>"><?= h($n) ?></option><?php endforeach; ?></select></div>
			<div class="csQuickSel"><select class="form-select form-select-sm nuListTomSelect" id="csQuickTable" data-placeholder="<?= h(t('cs_quick_table')) ?>" aria-label="<?= h(t('cs_quick_table')) ?>"><option value=""></option>
				<?php foreach ($quick['tables'] as $n): ?><option value="<?= h($n) ?>"><?= h($n) ?></option><?php endforeach; ?></select></div>
			<div class="csQuickSel"><select class="form-select form-select-sm nuListTomSelect" id="csQuickHash" data-placeholder="<?= h(t('cs_quick_hash')) ?>" aria-label="<?= h(t('cs_quick_hash')) ?>"><option value=""></option>
				<?php foreach ($quick['hash'] as $n): ?><option value="<?= h($n) ?>"><?= h($n) ?></option><?php endforeach; ?></select></div>
			<a class="btn btn-sm btn-outline-secondary<?= $s['preset'] === 'missing' ? ' active' : '' ?>" id="csQuickMissing" href="<?= h(nu_url('codesearch', ['preset' => 'missing', 'go' => '1', 'drafts' => '1', 'pages' => '1'])) ?>" title="<?= h(t('cs_quick_missing_tip')) ?>" data-bs-toggle="tooltip"><i class="bi bi-exclamation-diamond me-1"></i><?= h(t('cs_quick_missing')) ?></a>
		</div>
	</div>
</form>

<?php if ($s['q'] !== '' || $s['preset'] !== ''): ?>
<form method="post" class="card card-body shadow-sm mb-3 d-none" id="csSaveForm">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="search" value="<?= h(http_build_query($selfQ)) ?>">
	<div class="d-flex flex-wrap gap-2 align-items-center"><label class="small" for="csSaveName"><?= h(t('cs_save_name')) ?></label>
		<input type="text" class="form-control form-control-sm csSaveName" name="name" id="csSaveName" maxlength="100" value="<?= h(mb_substr($s['q'], 0, 60)) ?>" required>
		<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-bookmark-plus me-1"></i><?= h(t('save')) ?></button></div>
</form>
<?php endif; ?>

<?php
// recent replacements with Undo (globeadmin)
$batches = $canReplace ? NuCodeSearch::batches() : [];
if ($batches): ?>
<details class="card shadow-sm mb-3 csBatches" id="csBatches"<?= $rmode ? ' open' : '' ?>>
	<summary class="card-header small"><i class="bi bi-clock-history me-1"></i><?= h(t('cs_rp_batches')) ?> <span class="badge text-bg-light border"><?= count($batches) ?></span></summary>
	<ul class="list-group list-group-flush small">
	<?php foreach ($batches as $b): ?>
		<li class="list-group-item d-flex flex-wrap align-items-center gap-2 csBatch" data-id="<?= h((string)$b['id']) ?>">
			<span class="text-body-secondary"><?= h(date(nu_dfmt('d.m.Y H:i'), strtotime((string)$b['created_at']) ?: time())) ?> · <?= h((string)$b['login']) ?></span>
			<code><?= h(mb_strimwidth((string)$b['search'], 0, 60, '…')) ?></code> → <code><?= h(mb_strimwidth((string)$b['replace_with'], 0, 60, '…')) ?></code>
			<span class="text-body-secondary"><?= h(t('cs_rp_done', (int)$b['line_count'], (int)$b['part_count'])) ?></span>
			<?php if (!empty($b['undone_at'])): ?><span class="badge text-bg-secondary ms-auto"><?= h(t('cs_rp_undone_badge')) ?></span>
			<?php else: ?><form method="post" class="ms-auto" data-confirm="<?= h(t('cs_rp_undo_confirm')) ?>" data-confirm-ok="<?= h(t('cs_rp_undo')) ?>"><?= nu_csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="id" value="<?= h((string)$b['id']) ?>"><input type="hidden" name="search" value="<?= h(http_build_query($selfQ)) ?>">
				<button type="submit" class="btn btn-sm btn-outline-secondary py-0 csUndo"><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('cs_rp_undo')) ?></button></form><?php endif; ?>
		</li>
	<?php endforeach; ?>
	</ul>
</details>
<?php endif; ?>

<?php if (!$res): ?>
	<div class="text-body-secondary small csStart" id="csStart"><i class="bi bi-shield-check me-1"></i><?= h(t('cs_start')) ?></div>
<?php elseif ($res['error'] !== ''): ?>
	<div class="alert alert-danger" id="csError"><?= h($res['error']) ?></div>
<?php else: ?>
	<div class="d-flex flex-wrap align-items-center gap-2 mb-2 csSummary" id="csSummary" data-hits="<?= (int)$res['hits'] ?>" data-parts="<?= (int)$res['matched'] ?>">
		<strong><?= h(t('cs_result', $res['hits'], $res['matched'])) ?></strong>
		<span class="small text-body-secondary"><?= h(t('cs_result_sub', $res['sources'], $res['ms'])) ?></span>
		<?php if ($res['hits']): ?>
		<div class="ms-auto d-flex gap-2">
			<button type="button" class="btn btn-sm btn-outline-secondary" id="csToggleAll" data-expand="<?= h(t('cs_expand')) ?>" data-collapse="<?= h(t('cs_collapse')) ?>"><?= h(t('cs_collapse')) ?></button>
			<div class="btn-group btn-group-sm"><span class="btn btn-outline-secondary disabled"><i class="bi bi-download me-1"></i><?= h(t('exp_menu')) ?></span>
				<a class="btn btn-outline-secondary" id="csExpCsv" href="<?= h(nu_url('codesearch', $selfQ + ['export' => 'csv'])) ?>">CSV</a>
				<a class="btn btn-outline-secondary" id="csExpXlsx" href="<?= h(nu_url('codesearch', $selfQ + ['export' => 'xlsx'])) ?>">Excel</a></div>
		</div>
		<?php endif; ?>
	</div>
	<?php if ($rmode && $rpErr !== ''): ?><div class="alert alert-warning py-2 small" id="csRpErr"><?= h($rpErr) ?></div><?php endif; ?>
	<?php $rpOn = $rmode && $rpErr === '' && $res['hits'] > 0;
	if ($rpOn): ?>
	<form method="post" id="csRpForm" data-confirm="<?= h(t('cs_rp_confirm')) ?>" data-confirm-ok="<?= h(t('cs_rp_go_short')) ?>" data-confirm-class="btn btn-danger" data-nu-once>
		<?= nu_csrf_field() ?><input type="hidden" name="action" value="replace"><input type="hidden" name="search" value="<?= h(http_build_query($selfQ)) ?>"><input type="hidden" name="rp" value="<?= h($rp) ?>">
		<div class="alert alert-danger d-flex flex-wrap align-items-center gap-2 py-2 csRpBar" id="csRpBar">
			<i class="bi bi-arrow-left-right"></i><span class="small"><code><?= h(mb_strimwidth($s['q'], 0, 80, '…')) ?></code> → <code><?= h(mb_strimwidth($rp, 0, 80, '…')) ?></code></span>
			<div class="ms-auto d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="csRpAll"><?= h(t('cs_rp_all')) ?></button><button type="button" class="btn btn-sm btn-outline-secondary" id="csRpNone"><?= h(t('cs_rp_none')) ?></button>
			<button type="submit" class="btn btn-sm btn-danger" id="csRpGo" data-label="<?= h(t('cs_rp_go')) ?>" data-nu-busy="<?= h(t('cs_rp_go_short')) ?>"><?= h(t('cs_rp_go', 0)) ?></button></div>
		</div>
	<?php endif; ?>
	<?php if ($res['truncated']): ?><div class="alert alert-warning py-2 small" id="csTruncated"><?= h(t('cs_truncated', NuCodeSearch::MAX_HITS)) ?></div><?php endif; ?>
	<?php if (!$res['hits']): ?><div class="text-body-secondary" id="csNone"><?= h(t('cs_none')) ?></div><?php endif; ?>
	<div class="csResults" id="csResults">
	<?php foreach ($res['groups'] as $g): ?>
		<details class="card shadow-sm mb-2 csGroup" open data-kind="<?= h($g['kind']) ?>" data-key="<?= h($g['key']) ?>">
			<summary class="card-header d-flex flex-wrap align-items-center gap-2 csGroupHead">
				<i class="bi <?= h(NuCodeSearch::KINDS[$g['kind']] ?? 'bi-file-earmark') ?> text-body-secondary" aria-hidden="true"></i>
				<strong class="csGroupTitle"><?= h($g['title']) ?></strong>
				<?php if ($g['sub'] !== '' && $g['sub'] !== $g['title']): ?><span class="small text-body-secondary text-truncate csGroupSub"><?= h($g['sub']) ?></span><?php endif; ?>
				<?php if ($g['draft']): ?><span class="badge text-bg-warning csDraftBadge"><?= h(t('cs_draft')) ?></span><?php endif; ?>
				<?php if ($g['sys']): ?><span class="badge text-bg-secondary">nuBuilder Forte</span><?php endif; ?>
				<span class="badge rounded-pill text-bg-light border ms-auto csGroupHits"><?= (int)$g['hits'] ?></span>
				<a class="btn btn-sm btn-outline-secondary py-0 csGroupOpen" href="<?= h((string)$g['url']) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i><?= h(t('cs_open')) ?></a>
			</summary>
			<div class="card-body p-0">
			<?php foreach ($g['parts'] as $p): ?>
				<?php $why = $rpOn ? NuCodeSearch::blockReason($p, $drafts) : '-'; $tick = $rpOn && $why === ''; ?>
				<div class="csPart<?= $tick ? ' csRpPart' : '' ?>" data-place="<?= h($p['place']) ?>" data-lang="<?= h($p['lang']) ?>">
					<?php if ($tick): ?><input type="hidden" name="h[<?= h((string)$p['key']) ?>]" value="<?= h((string)$p['hash']) ?>"><?php endif; ?>
					<div class="csPartHead d-flex flex-wrap align-items-center gap-2 px-3 py-1">
						<span class="badge bg-body-secondary text-body-secondary border csPlace"><i class="bi <?= h(NuCodeSearch::PLACES[$p['place']][1] ?? 'bi-code') ?> me-1"></i><?= h(t(NuCodeSearch::PLACES[$p['place']][0] ?? 'cs_pl_own')) ?></span>
						<span class="csPartLabel"><?= h((string)$p['label']) ?></span>
						<span class="badge text-bg-light border csLang"><?= h($langNames[$p['lang']] ?? $p['lang']) ?></span>
						<?php if ($rpOn && $why !== ''): ?><span class="badge text-bg-light border text-wrap csRpNo"><i class="bi bi-slash-circle me-1"></i><?= h($why) ?></span><?php endif; ?>
						<a class="small ms-auto csPartOpen" href="<?= h((string)$p['url']) ?>" target="_blank" rel="noopener"><?= h(t('cs_open')) ?> <i class="bi bi-box-arrow-up-right"></i></a>
					</div>
					<div class="csCode">
					<?php foreach ($p['blocks'] as $bi => $b): ?>
						<?php if ($bi > 0): ?><div class="csGap" aria-hidden="true">⋯</div><?php endif; ?>
						<?php foreach ($b as $l): ?>
							<div class="csLine<?= $l['hit'] ? ' csHit' : '' ?>"><?php if ($l['hit']): ?><a class="csNo" href="<?= h(NuCodeSearch::lineUrl($p, (int)$l['n'], $s['r'] ? '' : ($s['m'] === 'phrase' ? $s['q'] : ''))) ?>" target="_blank" rel="noopener" title="<?= h(t('cs_line_tip')) ?>"><?= (int)$l['n'] ?></a><?php else: ?><span class="csNo"><?= (int)$l['n'] ?></span><?php endif; ?><?php if ($tick && $l['hit']): ?><input class="form-check-input csRpTick" type="checkbox" name="t[<?= h((string)$p['key']) ?>][]" value="<?= (int)$l['n'] ?>" checked aria-label="<?= h(t('cs_h_line') . ' ' . (int)$l['n']) ?>"><?php elseif ($tick): ?><span class="csRpTickGap"></span><?php endif; ?><code class="csText"><?= $l['html'] === '' ? ' ' : $l['html'] ?></code></div>
							<?php if ($tick && $l['hit']): ?><div class="csLine csNewLine"><span class="csNo">→</span><span class="csRpTickGap"></span><code class="csText"><?= h(NuCodeSearch::previewLine(rtrim($l['text'], "\r"), $rpPattern, $rpRepl)) ?: ' ' ?></code></div><?php endif; ?>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
		</details>
	<?php endforeach; ?>
	</div>
	<?php if ($rpOn): ?></form><?php endif; ?>
<?php endif;
nu_layout_end(nu_ts_foot() . '<script src="' . nu_asset('assets/js/nucodesearch.js') . '"></script>');
