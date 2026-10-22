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

$state = nu_browse_request();
$hash  = NuHash::forForm($meta, '', [], NuRender::props());          // ?ps= of the browse page
NuPhp::reset();
if (!NuPhp::run($meta->php('BB'), $hash, 'Before Browse')) nu_fatal(t('form_error'), implode("\n", array_column(NuPhp::$errors, 'message')), true);
$hash = NuPhp::$hash + $hash;

$max  = 50000;
$data = NuBrowse::query($meta, $hash, ['search' => trim($state['rf'] . ' ' . $state['q']), 'sort' => $state['sort'], 'dir' => $state['dir'], 'page' => 1, 'filters' => $state['flt'], 'nosearch' => $state['nsc'], 'searchonly' => $state['sc'], 'all' => $max]);
NuBrowse::dropTempTable();
NuLog::write('browse_exported', $meta->code(), '', ['rows' => count($data['rows'])]);

$cols  = array_values(array_filter($data['columns'], static fn($c) => $c['width'] > 0));
$lines = [];
foreach ($data['rows'] as $r) {
	$line = [];
	foreach ($cols as $c) $line[] = (string)$r['cells'][$c['index']];
	$lines[] = $line;
}
$head = array_map(static fn($c) => $c['title'], $cols);
// Excel (0.8.398): ?fmt=xlsx from the print view's "Export Excel"
if (($_GET['fmt'] ?? '') === 'xlsx') NuListExport::writeXlsx($meta->code() ?: 'export', $meta->description() ?: $meta->code(), $head, $lines, $state['q'] !== '' ? t('search') . ': ' . $state['q'] : '');
// options from the CSV dialog of the print view (sep, head, bom, name) – see core/nulistexport.php
NuListExport::writeCsv($meta->code() ?: 'export', $head, $lines);
