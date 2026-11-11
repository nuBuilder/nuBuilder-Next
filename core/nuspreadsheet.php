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
	/**
	 * Spreadsheets are read into memory as a whole (the library cannot stream them): reading a sheet needs about MEM_FACTOR times its
	 * unpacked XML (measured with the pack: 12–19×; a 1 MB .xlsx of 200,000 numbers unpacks to 6.6 MB and needs ~125 MB). Instead of a
	 * fixed file size (10 MB until 0.8.1094) the sheet is checked against the server's memory_limit before it is loaded (memoryCheck).
	 */
	public const MEM_FACTOR = 20;
	/** Memory kept free for the page itself (bytes). */
	public const MEM_RESERVE = 24 * 1048576;
	/** .xls is binary (no unpacked size to look at): file size × this must fit in the memory. */
	public const XLS_FACTOR = 40;

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

	/** PHP's memory_limit in bytes, -1 = no limit. */
	public static function memoryLimit(): int
	{
		$v = trim(nu_ini_get('memory_limit'));
		if ($v === '' || $v === '-1') return -1;
		$n = (int)$v;
		$n *= match (strtolower(substr($v, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
		return $n > 0 ? $n : -1;
	}

	/** Memory a sheet may use (bytes, -1 = no limit): memory_limit minus what this request uses and a reserve. */
	public static function memoryFree(?int $limit = null): int
	{
		$limit ??= self::memoryLimit();
		return $limit < 0 ? -1 : max(0, $limit - memory_get_usage() - self::MEM_RESERVE);
	}

	/** Largest upload (bytes) of this type the memory allows before looking inside: .xls by its file size, zipped types are checked by memoryCheck(). */
	public static function maxBytes(string $ext = 'xlsx', ?int $limit = null): int
	{
		$free = self::memoryFree($limit);
		if ($free < 0 || strtolower($ext) !== 'xls') return PHP_INT_MAX;
		return max(1048576, intdiv($free, self::XLS_FACTOR));
	}

	/**
	 * Unpacked XML size (bytes) the library reads for one sheet: the sheet's XML + shared strings (.xlsx), content.xml (.ods); null = unknown (.xls,
	 * a file that is not a zip).
	 */
	public static function unpackedSize(string $file, string $ext, string $sheetName = '', int $sheetIdx = 0): ?int
	{
		$ext = strtolower($ext);
		if (!in_array($ext, ['xlsx', 'ods'], true) || !class_exists('ZipArchive')) return null;
		$z = new ZipArchive();
		if ($z->open($file, ZipArchive::RDONLY) !== true) return null;
		try {
			$size = static fn(string $n): int => (int)(($st = $z->statName($n)) ? $st['size'] : 0);
			if ($ext === 'ods') return $size('content.xml') ?: null;
			$sheetPath = self::xlsxSheetPath($z, $sheetName, $sheetIdx);
			$all = 0;
			if ($sheetPath === null) {                       // unknown layout: every worksheet counts
				for ($i = 0; $i < $z->numFiles; $i++) { $st = $z->statIndex($i); if ($st && preg_match('#^xl/worksheets/[^/]+\.xml$#', $st['name'])) $all += (int)$st['size']; }
			} else $all = $size($sheetPath);
			return $all + $size('xl/sharedStrings.xml');
		} finally { $z->close(); }
	}

	/** Path of a sheet's XML inside an .xlsx (workbook.xml → relationship target), null when it cannot be found. */
	private static function xlsxSheetPath(ZipArchive $z, string $name, int $idx): ?string
	{
		$wb = $z->getFromName('xl/workbook.xml');
		$rels = $z->getFromName('xl/_rels/workbook.xml.rels');
		if (!is_string($wb) || !is_string($rels)) return null;
		if (!preg_match_all('#<(?:\w+:)?sheet\b[^>]*>#', $wb, $sm)) return null;
		$rid = null;
		foreach ($sm[0] as $i => $tag) {
			$n = preg_match('#\bname="([^"]*)"#', $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
			if (($name !== '' && $n === $name) || ($name === '' && $i === $idx)) { $rid = preg_match('#\br:id="([^"]+)"#', $tag, $m) ? $m[1] : null; break; }
		}
		if ($rid === null || !preg_match('#<Relationship\b[^>]*\bId="' . preg_quote($rid, '#') . '"[^>]*>#', $rels, $rm)
			|| !preg_match('#\bTarget="([^"]+)"#', $rm[0], $tm)) return null;
		$t = $tm[1];
		return str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . preg_replace('#^\./#', '', $t);
	}

	/**
	 * Refuses a sheet that would not fit in PHP's memory – before the library loads it (a fatal "Allowed memory size exhausted" otherwise).
	 * @throws RuntimeException pss_err_memory (with the hint to save the sheet as CSV)
	 */
	public static function memoryCheck(string $file, string $ext, string $sheetName = '', int $sheetIdx = 0, ?int $limit = null): void
	{
		$limit ??= self::memoryLimit();
		$free = self::memoryFree($limit);
		if ($free < 0) return;
		$ext = strtolower($ext);
		$need = $ext === 'xls' ? (int)@filesize($file) * self::XLS_FACTOR : (int)(self::unpackedSize($file, $ext, $sheetName, $sheetIdx) ?? 0) * self::MEM_FACTOR;
		if ($need > $free) throw new RuntimeException(t('pss_err_memory', (int)ceil($need / 1048576), (int)round($limit / 1048576)));
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
		self::memoryCheck($file, $ext, (string)$names[$idx], (int)$idx);   // 0.8.1094: instead of a fixed 10 MB file limit
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
