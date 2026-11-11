<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* CSV / Excel (?fmt=xlsx, 0.8.398) export of a browse list with the current search, sort and filters (needs the "print" right) – started from the print view's CSV dialog. */

NuTranslate::activate();   // texts in the reader's language (zzzzsys_translate)
$meta = NuFormMeta::load((string)($_GET['f'] ?? ''));
if (!$meta || !$meta->hasBrowse()) {
	http_response_code(404);
	nu_fatal(t('form_err_not_found'), '', true);
}
NuAcl::require($meta->id, 'print');
NuAcl::require($meta->id, 'browse');                       // access level "edit only" (0.8.461): no list, so no list export either

$state = nu_browse_request();
$hash  = NuHash::forForm($meta, '', [], NuRender::props());          // ?ps= of the browse page
NuPhp::reset();
if (!NuProcedure::hook('nu_before_save', $hash) || !NuPhp::run($meta->php('BB'), $hash, 'Before Browse')) nu_fatal(t('form_error'), implode("\n", array_column(NuPhp::$errors, 'message')), true);
$hash = NuPhp::$hash + $hash;

$max  = 50000;
$data = NuBrowse::query($meta, $hash, ['search' => $state['q'], 'runfilter' => $state['rf'], 'sort' => $state['sort'], 'dir' => $state['dir'], 'page' => 1, 'filters' => $state['flt'], 'adv' => $state['adv'], 'nosearch' => $state['nsc'], 'searchonly' => $state['sc'], 'all' => $max,
	'process' => NuOnProcess::rowsHook($meta->php('BB'), $hash), 'raw' => NuPrintView::raw($hash), 'totals' => ($_GET['fmt'] ?? '') === 'xlsx']);   // as the print view (0.8.958); Excel gets the row of totals
NuBrowse::dropTempTable();
NuLog::write('browse_exported', $meta->code(), '', ['rows' => count($data['rows'])]);

$cols  = array_values(NuPrintView::columns($data['columns'], $hash));   // nuPrintIncludeColumns() / ExcludeColumns() / IncludeHiddenColumns() (0.8.958)
$lines = [];
foreach ($data['rows'] as $r) {
	$line = [];
	foreach ($cols as $c) $line[] = NuHtmlClean::cellText((string)$r['cells'][$c['index']]);   // text of HTML cells (0.8.945)
	$lines[] = $line;
}
$head = array_map(static fn($c) => NuHtmlClean::cellText((string)$c['title']), $cols);
// Excel (0.8.398): ?fmt=xlsx from the print view's "Export Excel"
if (($_GET['fmt'] ?? '') === 'xlsx' && !empty($data['totals']) && $lines) {
	// the row of totals as the last line (not in CSV: it would come back as a record on a re-import); a sum is the bare number, others carry their name
	$line = []; $any = false;
	foreach ($cols as $c) {
		$tt = $data['totals'][$c['index']] ?? null;
		$line[] = $tt ? ($tt['fn'] === 'sum' ? $tt['text'] : $tt['label'] . ': ' . $tt['text']) : '';
		$any = $any || $tt;
	}
	if ($any) $lines[] = $line;
}
if (($_GET['fmt'] ?? '') === 'xlsx') NuListExport::writeXlsx($meta->code() ?: 'export', $meta->description() ?: $meta->code(), $head, $lines, $state['q'] !== '' ? t('search') . ': ' . $state['q'] : '');
// options from the CSV dialog of the print view (sep, head, bom, name) – see core/nulistexport.php
NuListExport::writeCsv($meta->code() ?: 'export', $head, $lines);
