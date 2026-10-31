<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * PhpSpreadsheet as a library pack (0.8.755, slot phpspreadsheet – built by dev/pss_assemble.py): Excel (.xlsx, .xls) and OpenDocument (.ods)
 * files for the CSV import, nuNextReadSpreadsheet() in form code and $nu->spreadsheet() in page forms. The classes are loaded only when
 * needed (core/nuapp.php registers an autoloader for PhpOffice\PhpSpreadsheet\…).
 *
 * Reading is "data only": formulas are never calculated – the value the file saved is taken –, external links and macros are not used
 * (.xlsm is not accepted), only the chosen sheet is loaded, rows and columns are limited (ReadFilter), dates become Y-m-d [H:i:s].
 */
final class NuSpreadsheet
{
	public const EXTS = ['xlsx', 'xls', 'ods'];
	public const TYPES = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'ods' => 'Ods'];
	public const EXTENSIONS = ['ctype', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'libxml', 'mbstring', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib'];
	public const MAX_ROWS = 200000;
	public const MAX_COLS = 500;
	/** Spreadsheet files are read into memory as a whole: their own size limit (MB), below the CSV import's. */
	public const MAX_MB = 10;

	private static ?bool $loaded = null;

	/** PHP extensions the library needs that this server lacks. */
	public static function missingExtensions(): array
	{
		return array_values(array_filter(self::EXTENSIONS, static fn($e) => !extension_loaded($e)));
	}

	/** Is reading spreadsheets possible here (pack installed, PHP version and extensions)? */
	public static function available(): bool
	{
		return NuLibs::activeDir('phpspreadsheet') !== null && NuLibs::phpProblem('phpspreadsheet') === '' && !self::missingExtensions();
	}

	/** Why not ('' = available): the text for pages and function errors. */
	public static function problem(): string
	{
		if (NuLibs::activeDir('phpspreadsheet') === null) return t('pss_missing');
		if (($p = NuLibs::phpProblem('phpspreadsheet')) !== '') return $p;
		$m = self::missingExtensions();
		return $m ? t('lp_test_ext', implode(', ', $m)) : '';
	}

	/** Loads the pack's autoloader once. */
	public static function load(): bool
	{
		if (self::$loaded !== null) return self::$loaded;
		$dir = NuLibs::activeDir('phpspreadsheet');
		if ($dir === null || NuLibs::phpProblem('phpspreadsheet') !== '' || !is_file($dir . '/autoload.php')) return self::$loaded = false;
		require_once $dir . '/autoload.php';
		return self::$loaded = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
	}

	public static function version(): string
	{
		$dir = NuLibs::activeDir('phpspreadsheet');
		return $dir !== null && is_file($dir . '/VERSION') ? trim((string)file_get_contents($dir . '/VERSION')) : '';
	}

	/** Is this file name a spreadsheet the library can read here? */
	public static function isSpreadsheet(string $name): bool
	{
		return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTS, true);
	}

	/** @return \PhpOffice\PhpSpreadsheet\Reader\IReader */
	private static function reader(string $file, string $ext): object
	{
		if (!self::load()) throw new RuntimeException(self::problem() ?: t('pss_missing'));
		$type = self::TYPES[strtolower($ext)] ?? '';
		if ($type === '') throw new RuntimeException(t('imp_err_type_xlsx'));
		$r = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($type);
		if (!$r->canRead($file)) throw new RuntimeException(t('imp_err_sheet'));
		return $r;
	}

	/** Names of the sheets. $ext: the type when $file has no extension (uploaded temp files). */
	public static function sheets(string $file, string $ext = ''): array
	{
		$ext = $ext !== '' ? $ext : pathinfo($file, PATHINFO_EXTENSION);
		try {
			return array_values(array_map('strval', self::reader($file, $ext)->listWorksheetNames($file)));
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RuntimeException(t('imp_err_sheet'));
		}
	}

	/**
	 * Reads one sheet (index or name) as rows of texts. Empty rows at the end are dropped; a row shorter than the widest is not padded.
	 * @return array{sheets: list<string>, sheet: int, rows: list<list<string>>, truncated: bool}
	 */
	public static function read(string $file, int|string $sheet = 0, string $ext = '', int $maxRows = self::MAX_ROWS): array
	{
		$ext = $ext !== '' ? $ext : pathinfo($file, PATHINFO_EXTENSION);
		$names = self::sheets($file, $ext);
		if (!$names) throw new RuntimeException(t('imp_err_sheet'));
		$idx = is_int($sheet) ? $sheet : array_search($sheet, $names, true);
		if ($idx === false || !isset($names[$idx])) throw new RuntimeException(t('pss_err_no_sheet', (string)$sheet));
		try {
			$r = self::reader($file, $ext);
			$r->setReadEmptyCells(false);
			if (method_exists($r, 'setIncludeCharts')) $r->setIncludeCharts(false);
			$r->setLoadSheetsOnly([$names[$idx]]);
			require_once NU_ROOT . '/core/nuspreadsheetfilter.php';    // implements the pack's interface: only after load(), so not in NU_AUTOLOAD
			$r->setReadFilter(new NuSpreadsheetFilter(
				$maxRows + 1, self::MAX_COLS));
			$book = $r->load($file);
			$ws = $book->getSheet(0);
			$lastRow = min($ws->getHighestDataRow(), $maxRows + 1);
			$lastCol = min(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ws->getHighestDataColumn()), self::MAX_COLS);
			$rows = [];
			for ($y = 1; $y <= $lastRow; $y++) {
				$row = [];
				for ($x = 1; $x <= $lastCol; $x++) {
					$c = $ws->cellExists([$x, $y]) ? $ws->getCell([$x, $y]) : null;
					$row[] = $c === null ? '' : self::text($c);
				}
				while ($row && end($row) === '') array_pop($row);
				$rows[] = $row;
			}
			while ($rows && end($rows) === []) array_pop($rows);
			$truncated = count($rows) > $maxRows;
			if ($truncated) $rows = array_slice($rows, 0, $maxRows);
			$book->disconnectWorksheets();
			return ['sheets' => $names, 'sheet' => (int)$idx, 'rows' => $rows, 'truncated' => $truncated];
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			error_log('nuBuilder Next spreadsheet: ' . $e->getMessage());
			throw new RuntimeException(t('imp_err_sheet'));
		}
	}

	/** A cell as text: the saved result of a formula (never calculated), dates as Y-m-d [H:i:s], numbers without float noise. */
	private static function text(\PhpOffice\PhpSpreadsheet\Cell\Cell $c): string
	{
		$v = $c->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA ? $c->getOldCalculatedValue() : $c->getValue();
		if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) return $v->getPlainText();
		if ($v === null) return '';
		if (is_bool($v)) return $v ? '1' : '0';
		if ((is_int($v) || is_float($v)) && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($c, $v)) {
			try {
				$d = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v);
				if ((float)$v < 1) return $d->format('H:i:s');                     // a time only
				return $d->format(fmod((float)$v, 1.0) === 0.0 ? 'Y-m-d' : 'Y-m-d H:i:s');
			} catch (Throwable) {
				return (string)$v;
			}
		}
		if (is_float($v)) {
			if ($v == floor($v) && abs($v) < 1e15) return (string)(int)$v;
			$s = rtrim(rtrim(sprintf('%.12F', $v), '0'), '.');
			return $s === '-0' ? '0' : $s;
		}
		return (string)$v;
	}
}
