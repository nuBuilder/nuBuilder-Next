<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Print and CSV of nuBuilder Next's own lists (users, access levels, forms, log …) – like the Print / CSV buttons of form browse lists.
 * Allowed with the "Print" right (slf_print_button) of the matching nuBuilder form (nuuser, nuaccess …); the globeadmin always.
 * The current search, filters and sort apply; all rows are exported (print: up to MAX_PRINT, CSV: up to MAX_CSV).
 *
 * Like nuBuilder: the list's Print button opens the rows as an HTML table in a new tab; there, Copy puts the table on the clipboard and
 * Export CSV asks for the options (delimiter, column titles, BOM, file name) and downloads the file.
 *
 *   ?p=users&q=…&export=print   → HTML table in a new tab (Copy / Export CSV buttons)
 *   ?p=users&q=…&export=csv     → CSV download; options sep (semicolon|comma|tab|pipe, default $nuConfigCSVSeparator or ";"),
 *                                  head (1/0 column titles), bom (1/0 UTF-8 BOM for Excel), name (file name)
 *
 * In a page, before nu_layout_start():
 *   NuListExport::handle(NuAcl::FORM_USER, t('nav_users'), [label => fn(array $row): string, …], fn(int $limit): array => rows, $filterText);
 * and in the toolbar: NuListExport::buttons(NuAcl::FORM_USER, 'users', $keep).
 * Form browse lists (pages/nuform.php print view, pages/nuformexport.php) use csvDialog() and writeCsv() too.
 */
final class NuListExport
{
	public const MAX_PRINT = 5000;
	public const MAX_CSV   = 50000;
	public const SEPARATORS = ['semicolon' => ';', 'comma' => ',', 'tab' => "\t", 'pipe' => '|'];

	public static function format(): ?string
	{
		$f = (string)($_GET['export'] ?? '');
		return in_array($f, ['csv', 'print', 'xlsx'], true) ? $f : null;
	}

	public static function allowed(string $formId): bool
	{
		return NuAcl::can($formId, 'print');
	}

	/** Address of the print view of a list ('' without the print right) – for the ⋮ menu on phones (0.8.371). */
	public static function printUrl(string $formId, string $page, array $keep): string
	{
		if (!self::allowed($formId)) return '';
		return nu_url($page, array_filter($keep, static fn($v) => $v !== null && $v !== '') + ['export' => 'print']);
	}

	/**
	 * Writes the export and ends the request when one is asked for; does nothing otherwise.
	 * @param array<string, callable(array): (string|int|float|null)> $columns
	 * @param callable(int): array $rows  query with the page's search / filters / sort, at most $limit rows
	 */
	public static function handle(string $formId, string $title, array $columns, callable $rows, string $filter = ''): void
	{
		$format = self::format();
		if ($format === null) return;
		NuAcl::require($formId, 'print');
		$limit = $format === 'print' ? self::MAX_PRINT : self::MAX_CSV;
		$data  = $rows($limit);
		$lines = [];
		foreach ($data as $r) {
			$line = [];
			foreach ($columns as $fn) $line[] = (string)($fn($r) ?? '');
			$lines[] = $line;
		}
		NuLog::write('browse_exported', (string)($_GET['p'] ?? $formId), '', ['format' => $format, 'rows' => count($lines)]);
		$base = (string)($_GET['p'] ?? 'list');
		$head = array_keys($columns);
		if ($format !== 'print') [$head, $lines] = self::onlyColumns($head, $lines);   // columns hidden in the print view (0.8.398)
		if ($format === 'csv') self::writeCsv($base, $head, $lines);
		if ($format === 'xlsx') self::writeXlsx($base, $title, $head, $lines, $filter);
		$params = array_diff_key($_GET, ['export' => 1, 'sep' => 1, 'head' => 1, 'bom' => 1, 'name' => 1, 'cols' => 1]);
		self::printPage($title, $head, $lines, $filter, count($data) >= $limit, self::csvDialog($params + ['export' => 'csv'], $base) . self::xlsxButton($params + ['export' => 'xlsx']));
	}

	/**
	 * Export button of a list toolbar (0.8.398, before: Print): a menu with HTML page (the print view in a new tab), CSV … (the options
	 * dialog) and Excel (download). Nothing without the print right. $small: btn-sm; $class: the button style; $wrap: classes of the group
	 * (e.g. hidden on phones – the CSV dialog stays outside it).
	 */
	public static function buttons(string $formId, string $page, array $keep, bool $small = false, string $class = 'btn-outline-secondary nuToolBtn', string $wrap = ''): string
	{
		if (!self::allowed($formId)) return '';
		$params = array_filter($keep, static fn($v) => $v !== null && $v !== '');
		return self::menu(nu_url($page, $params + ['export' => 'print']), nu_url($page, $params + ['export' => 'xlsx']),
			self::csvDialog(['p' => $page] + $params + ['export' => 'csv'], $page, false), 'nuListPrint', 'nuListExportBtn', $small, $class, $wrap);
	}

	/** The Export menu (lists and form browse lists): $id on the group, $btnId on the button; the CSV dialog is appended after the group. */
	public static function menu(string $printUrl, string $xlsxUrl, string $csvDialog, string $id, string $btnId, bool $small, string $class, string $wrap = ''): string
	{
		return '<div class="dropdown d-inline-block nuExportMenu' . ($wrap !== '' ? ' ' . h($wrap) : '') . '" id="' . h($id) . '" data-print-url="' . h($printUrl) . '">'
			. '<button class="btn' . ($small ? ' btn-sm' : '') . ' ' . h($class) . ' dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="' . h($btnId) . '">'
			. '<i class="bi bi-box-arrow-up-right me-1"></i>' . h(t('exp_menu')) . '</button>'
			. '<ul class="dropdown-menu shadow-sm">' . self::menuItems($printUrl, $xlsxUrl) . '</ul></div>' . $csvDialog;
	}

	/** The three entries (also in the ⋮ menu of phones). */
	public static function menuItems(string $printUrl, string $xlsxUrl): string
	{
		return '<li><a class="dropdown-item nuExpHtml" href="' . h($printUrl) . '" target="_blank" rel="noopener"><i class="bi bi-filetype-html me-2"></i>' . h(t('exp_html')) . '</a></li>'
			. '<li><button class="dropdown-item nuExpCsv" type="button" data-bs-toggle="modal" data-bs-target="#nuCsvDialog"><i class="bi bi-filetype-csv me-2"></i>' . h(t('exp_csv')) . '</button></li>'
			. '<li><a class="dropdown-item nuExpXlsx" href="' . h($xlsxUrl) . '"><i class="bi bi-file-earmark-excel me-2"></i>' . h(t('exp_xlsx')) . '</a></li>';
	}

	/** Default separator key: $nuConfigCSVSeparator (";" when empty or not one of SEPARATORS). */
	private static function defaultSep(): string
	{
		$key = array_search((string)(nu_cfg('nuConfigCSVSeparator') ?: ';'), self::SEPARATORS, true);
		return $key === false ? 'semicolon' : (string)$key;
	}

	/** File name without extension: the given base + date/time, only letters, digits, _ and -. */
	private static function fileBase(string $base): string
	{
		return trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $base), '_') . '_' . date('Ymd_His');
	}

	/**
	 * "Export CSV" button + options dialog (GET to index.php with $params, e.g. ['p' => 'users', 'q' => …, 'export' => 'csv']).
	 * Needs Bootstrap's JS; assets/js/nuapp.js remembers the chosen options per browser.
	 */
	public static function csvDialog(array $params, string $base, bool $button = true): string
	{
		$hidden = '';
		foreach ($params as $k => $v) {
			if (is_array($v)) { foreach ($v as $ik => $iv) if (!is_array($iv)) $hidden .= '<input type="hidden" name="' . h($k . '[' . $ik . ']') . '" value="' . h((string)$iv) . '">'; }
			elseif ($v !== null && $v !== '') $hidden .= '<input type="hidden" name="' . h((string)$k) . '" value="' . h((string)$v) . '">';
		}
		$sepOpts = '';
		foreach (array_keys(self::SEPARATORS) as $key) $sepOpts .= '<option value="' . $key . '"' . ($key === self::defaultSep() ? ' selected' : '') . '>' . h(t('csv_sep_' . $key)) . '</option>';
		return ($button ? '<button type="button" class="btn btn-sm btn-outline-secondary d-print-none" data-bs-toggle="modal" data-bs-target="#nuCsvDialog" id="nuCsvButton"><i class="bi bi-filetype-csv me-1"></i>' . h(t('csv_export')) . '</button>' : '')
			. '<div class="modal fade d-print-none" id="nuCsvDialog" tabindex="-1" aria-labelledby="nuCsvDialogTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
			. '<form method="get" action="index.php" class="js-csv-form">' . $hidden
			. '<div class="modal-header"><h2 class="modal-title fs-5" id="nuCsvDialogTitle"><i class="bi bi-filetype-csv me-2"></i>' . h(t('csv_export')) . '</h2>'
			. '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . h(t('close')) . '"></button></div>'
			. '<div class="modal-body">'
			. '<div class="mb-3"><label class="form-label" for="nuCsvSep">' . h(t('csv_sep')) . '</label><select class="form-select" id="nuCsvSep" name="sep">' . $sepOpts . '</select></div>'
			. '<div class="mb-3"><label class="form-label" for="nuCsvName">' . h(t('csv_name')) . '</label><div class="input-group">'
			. '<input type="text" class="form-control" id="nuCsvName" name="name" value="' . h(self::fileBase($base)) . '" maxlength="100" spellcheck="false"><span class="input-group-text">.csv</span></div></div>'
			. '<input type="hidden" name="head" value="0"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="nuCsvHead" name="head" value="1" checked><label class="form-check-label" for="nuCsvHead">' . h(t('csv_head')) . '</label></div>'
			. '<input type="hidden" name="bom" value="0"><div class="form-check"><input class="form-check-input" type="checkbox" id="nuCsvBom" name="bom" value="1" checked><label class="form-check-label" for="nuCsvBom">' . h(t('csv_bom')) . '</label></div>'
			. '</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' . h(t('cancel')) . '</button>'
			. '<button type="submit" class="btn btn-primary"><i class="bi bi-download me-1"></i>' . h(t('csv_start')) . '</button></div>'
			. '</form></div></div></div>';
	}

	/** ?cols=0,2,5 (the visible columns of the print view): only these columns, in this order. Without it: all. */
	public static function onlyColumns(array $head, array $lines): array
	{
		$raw = $_GET['cols'] ?? null;
		if (!is_string($raw) || !preg_match('/^\d{1,3}(,\d{1,3})*$/', $raw)) return [$head, $lines];
		$keep = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn($i) => $i < count($head))));
		if (!$keep) return [$head, $lines];
		$pick = static fn(array $a) => array_map(static fn($i) => $a[$i] ?? '', $keep);
		return [$pick(array_values($head)), array_map(static fn($l) => $pick(array_values($l)), $lines)];
	}

	/** "Export Excel" button (a link: the download starts at once – bold header row, frozen, autofilter, numbers as numbers). */
	public static function xlsxButton(array $params): string
	{
		return '<a class="btn btn-sm btn-outline-secondary d-print-none" id="nuXlsxButton" href="' . h(nu_url((string)($params['p'] ?? 'home'), array_diff_key($params, ['p' => 1]))) . '">'
			. '<i class="bi bi-file-earmark-excel me-1"></i>' . h(t('xlsx_export')) . '</a>';
	}

	/**
	 * Sends the rows as an Excel file (NuXlsx) and ends the request: sheet = the title, header row bold and frozen with an autofilter,
	 * text that is a plain number becomes a number; the search / filter text in the file properties.
	 */
	public static function writeXlsx(string $base, string $title, array $head, array $lines, string $filter = ''): never
	{
		$x = NuXlsx::create($title)->subject($filter)->company(nu_app_title());
		$x->sheet($title !== '' ? $title : 'Export')->numbersFromText()->header($head)->rows($lines);
		$x->download(self::fileBase($base) . '.xlsx');
	}

	/**
	 * Sends a CSV download with the options of the request (sep, head, bom, name – see the class comment) and ends the request.
	 * Cells starting with = + - @ (not numbers) get a leading ' so that Excel does not run them as formulas.
	 */
	public static function writeCsv(string $base, array $head, array $lines): never
	{
		$sep  = self::SEPARATORS[(string)($_GET['sep'] ?? '')] ?? self::SEPARATORS[self::defaultSep()];
		$name = trim((string)preg_replace('/[^A-Za-z0-9_. -]+/', '_', (string)preg_replace('/\.csv$/i', '', trim((string)($_GET['name'] ?? '')))), ' ._');
		$name = ($name !== '' ? mb_substr($name, 0, 100) : self::fileBase($base)) . '.csv';
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $name . '"');
		header('Cache-Control: no-store');
		$out = fopen('php://output', 'w');
		if (($_GET['bom'] ?? '1') !== '0') fwrite($out, "\xEF\xBB\xBF");    // BOM: Excel opens UTF-8 correctly
		if (($_GET['head'] ?? '1') !== '0') fputcsv($out, $head, $sep, '"', '');
		foreach ($lines as $line) {
			foreach ($line as &$v) {
				if ($v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) $v = "'" . $v;   // no formulas in Excel
			}
			unset($v);
			fputcsv($out, $line, $sep, '"', '');
		}
		fclose($out);
		exit;
	}

	private static function printPage(string $title, array $head, array $lines, string $filter, bool $cut, string $csv): never
	{
		header('Cache-Control: no-store');
		nu_head($title);
		?>
<body class="nu-print-list">
<main class="container-fluid py-3">
	<div class="nuTitleBar mb-2">
		<h1 class="h5 mb-0"><?= h($title) ?></h1>
		<div class="nuTitleSub small text-body-secondary"><?= h(date('d.m.Y H:i')) ?> · <?= h(t('n_records', count($lines))) ?><?= $filter !== '' ? ' · ' . h($filter) : '' ?></div>
		<?php if ($cut): ?><div class="nuTitleSub small text-warning-emphasis"><?= h(t('list_print_cut', count($lines))) ?></div><?php endif; ?>
		<div class="d-flex flex-wrap gap-2 d-print-none">
			<button class="btn btn-sm btn-primary" type="button" data-nu-copy-table=".nu-print-table" data-nu-copied="<?= h(t('copy_done')) ?>" data-nu-copy-failed="<?= h(t('copy_failed')) ?>"><i class="bi bi-clipboard me-1"></i><span><?= h(t('copy_table')) ?></span></button>
			<?= $csv ?>
			<button class="btn btn-sm btn-outline-secondary" type="button" id="nuPrintUndo" disabled><i class="bi bi-arrow-counterclockwise me-1"></i><?= h(t('print_undo')) ?></button>
			<button class="btn btn-sm btn-outline-secondary" type="button" id="nuPrintReset" disabled><i class="bi bi-arrow-repeat me-1"></i><?= h(t('print_reset_view')) ?></button>
			<span class="small text-body-secondary align-self-center d-none" id="nuPrintHiddenInfo" data-text="<?= h(t('print_cols_hidden', '%s')) ?>"></span>
		</div>
	</div>
	<table class="table table-sm table-bordered nu-print-table">
		<thead><tr><?php foreach ($head as $hd): // × hides the column (0.8.397, assets/js/nuprintlist.js) ?><th><span class="nuPrintColTitle"><?= h($hd) ?></span><button type="button" class="nuColHide d-print-none" aria-label="<?= h(t('print_col_hide', $hd)) ?>" title="<?= h(t('print_col_hide', $hd)) ?>"><i class="bi bi-x"></i></button></th><?php endforeach; ?></tr></thead>
		<tbody>
		<?php foreach ($lines as $line): ?>
			<tr><?php foreach ($line as $v): ?><td><?= h($v) ?></td><?php endforeach; ?></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</main>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuprintlist.js') ?>"></script>
</body>
</html>
<?php
		exit;
	}

	/** "Search: x · Group: y" for the print header. */
	public static function filterText(array $parts): string
	{
		$out = [];
		foreach ($parts as $label => $value) if ((string)$value !== '') $out[] = $label . ': ' . $value;
		return implode(' · ', $out);
	}
}
