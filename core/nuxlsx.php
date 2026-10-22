<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Excel export (0.8.398): a small writer for .xlsx files (Office Open XML) without other libraries – only PHP's ZipArchive.
 * For the list exports of nuBuilder Next and for own code (procedures, page forms: $nu->excel()).
 *
 *   $x = NuXlsx::create('Customers');                         // workbook (title = file property)
 *   $s = $x->sheet('Customers');                              // a sheet (name made valid and unique)
 *   $s->header(['Name', 'Amount', 'Since'])                   // bold, header row frozen, autofilter
 *     ->row(['Anna', 1250.5, new DateTime('2026-01-15')])     // int / float = number, DateTimeInterface = date, bool, string = text
 *     ->row(['Total', NuXlsx::formula('SUM(B2:B2)')], ['bold' => true])
 *     ->style('B2:B3', ['format' => '#,##0.00'])
 *     ->width('A', 30);                                       // or autoWidth() (default on)
 *   $x->download('customers.xlsx');                           // or save($file) / toString()
 *
 * Cell styles (arrays): bold, italic, underline, strike (bool), size (points), font, color, bg ('#RRGGBB'), format (Excel number
 * format, e.g. '0.00', '#,##0', 'dd.mm.yyyy', '0%'), align (left / center / right), valign (top / middle / bottom), wrap (bool),
 * border (thin / medium / thick / dashed / dotted / double). One cell: NuXlsx::cell($value, $style).
 *
 * Safe by design: a string is always text (never a formula, also when it starts with = + - @), invalid XML characters are removed,
 * names and ranges are checked. Formulas only through NuXlsx::formula().
 */
final class NuXlsx
{
	public const MAX_ROWS = 1048576;
	public const MAX_COLS = 16384;
	public const MAX_TEXT = 32767;
	public const STYLE_KEYS = ['bold', 'italic', 'underline', 'strike', 'size', 'font', 'color', 'bg', 'format', 'align', 'valign', 'wrap', 'border'];
	private const BORDERS = ['thin', 'medium', 'thick', 'dashed', 'dotted', 'double', 'hair'];
	private const BUILTIN_FORMATS = ['0' => 1, '0.00' => 2, '#,##0' => 3, '#,##0.00' => 4, '0%' => 9, '0.00%' => 10, '0.00E+00' => 11, '@' => 49];

	/** @var list<NuXlsxSheet> */
	private array $sheets = [];
	private array $props = ['title' => '', 'subject' => '', 'author' => '', 'company' => '', 'keywords' => '', 'description' => ''];
	/** Date / date-time formats of DateTimeInterface values; default = Excel's built-in formats 14 / 22 (shown in the reader's locale). */
	private string $dateFormat = '';
	private string $dateTimeFormat = '';
	/** @var array<string, int> style key => cellXfs index (built in toString()) */
	private array $xfIndex = [];

	public function __construct(string $title = '')
	{
		$this->props['title'] = $title;
		$this->props['author'] = 'nuBuilder Next';
	}

	public static function create(string $title = ''): self
	{
		return new self($title);
	}

	/** A cell with its own style (or an explicit value) for row(). */
	public static function cell(mixed $value, array $style = []): NuXlsxCell
	{
		return new NuXlsxCell($value, self::cleanStyle($style));
	}

	/** A formula cell: NuXlsx::formula('SUM(B2:B9)') – with or without the leading '='. */
	public static function formula(string $formula, array $style = []): NuXlsxCell
	{
		$f = ltrim(trim($formula), '=');
		if ($f === '' || mb_strlen($f) > 8192) throw new InvalidArgumentException('NuXlsx: formula empty or too long');
		return new NuXlsxCell($f, self::cleanStyle($style), true);
	}

	/** Adds a sheet (the name is made valid: at most 31 characters, without : \ / ? * [ ] – and unique). */
	public function sheet(string $name = ''): NuXlsxSheet
	{
		$s = new NuXlsxSheet($this->uniqueName($name !== '' ? $name : 'Sheet' . (count($this->sheets) + 1)));
		$this->sheets[] = $s;
		return $s;
	}

	/** @return list<NuXlsxSheet> */
	public function sheets(): array { return $this->sheets; }

	public function title(string $v): self { $this->props['title'] = $v; return $this; }
	public function subject(string $v): self { $this->props['subject'] = $v; return $this; }
	public function author(string $v): self { $this->props['author'] = $v; return $this; }
	public function company(string $v): self { $this->props['company'] = $v; return $this; }
	public function keywords(string $v): self { $this->props['keywords'] = $v; return $this; }
	public function description(string $v): self { $this->props['description'] = $v; return $this; }

	/** Number formats of date values, e.g. 'dd.mm.yyyy' and 'dd.mm.yyyy hh:mm' ('' = Excel's formats in the reader's locale). */
	public function dateFormats(string $date, string $dateTime = ''): self
	{
		$this->dateFormat = $date;
		$this->dateTimeFormat = $dateTime;
		return $this;
	}

	// ================================================================= output

	/** Writes the file (the folder must exist). */
	public function save(string $file): void
	{
		if (file_put_contents($file, $this->toString()) === false) throw new RuntimeException('NuXlsx: cannot write ' . basename($file));
	}

	/** Sends the file as a download and ends the request. */
	public function download(string $filename = 'export.xlsx'): never
	{
		$name = trim((string)preg_replace('/[^A-Za-z0-9_. -]+/', '_', $filename), ' ._') ?: 'export';
		if (!preg_match('/\.xlsx$/i', $name)) $name .= '.xlsx';
		$data = $this->toString();
		while (ob_get_level() > 0) ob_end_clean();
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $name . '"');
		header('Content-Length: ' . strlen($data));
		header('Cache-Control: no-store');
		echo $data;
		exit;
	}

	/** The .xlsx file as a string. */
	public function toString(): string
	{
		if (!class_exists('ZipArchive')) throw new RuntimeException('NuXlsx: the PHP extension zip is missing');
		if (!$this->sheets) $this->sheet();
		$strings = new NuXlsxStrings();
		$styles = $this->collectStyles();
		$parts = [];
		foreach ($this->sheets as $i => $s) $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $s->xml($strings, fn(array $st, string $kind) => $this->xf($st, $kind));
		$parts['xl/sharedStrings.xml'] = $strings->xml();
		$parts['xl/styles.xml'] = $styles;
		$parts['xl/workbook.xml'] = $this->workbookXml();
		$parts['xl/_rels/workbook.xml.rels'] = $this->workbookRels();
		$parts['[Content_Types].xml'] = $this->contentTypes();
		$parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
			. '</Relationships>';
		$parts['docProps/core.xml'] = $this->coreXml();
		$parts['docProps/app.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>nuBuilder Next</Application>'
			. ($this->props['company'] !== '' ? '<Company>' . self::esc($this->props['company']) . '</Company>' : '') . '</Properties>';

		$tmp = tempnam(sys_get_temp_dir(), 'nuxlsx');
		if ($tmp === false) throw new RuntimeException('NuXlsx: no temporary file');
		try {
			$z = new ZipArchive();
			if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('NuXlsx: cannot create the zip file');
			foreach (['[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/sharedStrings.xml'] as $n) {
				$z->addFromString($n, $parts[$n]);
				unset($parts[$n]);
			}
			foreach ($parts as $n => $xml) $z->addFromString($n, $xml);
			$z->close();
			return (string)file_get_contents($tmp);
		} finally {
			@unlink($tmp);
		}
	}

	// ================================================================= helpers (also used by NuXlsxSheet)

	/** XML text: escaped, invalid XML characters removed. */
	public static function esc(string $s): string
	{
		$s = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($s, 'UTF-8'));
		return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/** Column letters of a 1-based column number (1 = A, 27 = AA). */
	public static function colName(int $n): string
	{
		if ($n < 1 || $n > self::MAX_COLS) throw new InvalidArgumentException('NuXlsx: column out of range');
		$s = '';
		for (; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
		return $s;
	}

	/** 1-based column number of letters ('A' = 1) or a number. */
	public static function colNumber(string|int $c): int
	{
		if (is_int($c)) $n = $c;
		else {
			if (!preg_match('/^[A-Za-z]{1,3}$/', $c)) throw new InvalidArgumentException('NuXlsx: invalid column ' . $c);
			$n = 0;
			foreach (str_split(strtoupper($c)) as $ch) $n = $n * 26 + (ord($ch) - 64);
		}
		if ($n < 1 || $n > self::MAX_COLS) throw new InvalidArgumentException('NuXlsx: column out of range');
		return $n;
	}

	/** [row1, col1, row2, col2] of 'A1' or 'A1:C9'. */
	public static function range(string $r): array
	{
		if (!preg_match('/^([A-Za-z]{1,3})([1-9]\d{0,6})(?::([A-Za-z]{1,3})([1-9]\d{0,6}))?$/', trim($r), $m)) throw new InvalidArgumentException('NuXlsx: invalid range ' . $r);
		$c1 = self::colNumber($m[1]); $r1 = (int)$m[2];
		$c2 = isset($m[3]) && $m[3] !== '' ? self::colNumber($m[3]) : $c1; $r2 = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : $r1;
		if ($r1 > self::MAX_ROWS || $r2 > self::MAX_ROWS) throw new InvalidArgumentException('NuXlsx: row out of range');
		return [min($r1, $r2), min($c1, $c2), max($r1, $r2), max($c1, $c2)];
	}

	/** Excel serial number of a date (days since 1899-12-30, local wall time). */
	public static function serial(DateTimeInterface $d): float
	{
		$utc = new DateTimeImmutable($d->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
		return $utc->getTimestamp() / 86400 + 25569;
	}

	/** Only known style keys with checked values. */
	public static function cleanStyle(array $st): array
	{
		$out = [];
		foreach ($st as $k => $v) {
			if (!in_array($k, self::STYLE_KEYS, true) || $v === null || $v === false || $v === '') continue;
			switch ($k) {
				case 'bold': case 'italic': case 'underline': case 'strike': case 'wrap': $out[$k] = true; break;
				case 'size': $n = (float)$v; if ($n >= 1 && $n <= 409) $out[$k] = $n; break;
				case 'font': if (preg_match('/^[\w .\-]{1,31}$/u', (string)$v)) $out[$k] = (string)$v; break;
				case 'color': case 'bg': if (preg_match('/^#?([0-9a-f]{6})$/i', (string)$v, $m)) $out[$k] = strtoupper($m[1]); break;
				case 'format': if (mb_strlen((string)$v) <= 200) $out[$k] = (string)$v; break;
				case 'align': if (in_array($v, ['left', 'center', 'right'], true)) $out[$k] = $v; break;
				case 'valign': if (in_array($v, ['top', 'middle', 'bottom'], true)) $out[$k] = $v; break;
				case 'border': if (in_array($v, self::BORDERS, true) || $v === true) $out[$k] = $v === true ? 'thin' : $v; break;
			}
		}
		ksort($out);
		return $out;
	}

	// ================================================================= internals

	private function uniqueName(string $name): string
	{
		$n = trim((string)preg_replace('/[:\\\\\/?*\[\]]/', ' ', $name), " '");
		$n = mb_substr($n !== '' ? $n : 'Sheet', 0, 31);
		$taken = array_map(static fn(NuXlsxSheet $s) => mb_strtolower($s->name()), $this->sheets);
		$base = $n; $i = 2;
		while (in_array(mb_strtolower($n), $taken, true)) $n = mb_substr($base, 0, 31 - strlen((string)$i) - 1) . ' ' . $i++;
		return $n;
	}

	/** styles.xml from every style used in the sheets; fills $this->xfIndex. */
	private function collectStyles(): string
	{
		$used = [];
		foreach ($this->sheets as $s) foreach ($s->usedStyles() as $k => [$st, $kind]) $used[$k] = [$st, $kind];
		$fonts = ['<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'];
		$fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
		$borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
		$numFmts = [];
		$xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
		$this->xfIndex = ['|' => 0];
		foreach ($used as $key => [$st, $kind]) {
			if ($st === [] && $kind === '') { $this->xfIndex[$key] = 0; continue; }
			$font = '<font>' . (!empty($st['bold']) ? '<b/>' : '') . (!empty($st['italic']) ? '<i/>' : '') . (!empty($st['strike']) ? '<strike/>' : '')
				. (!empty($st['underline']) ? '<u/>' : '') . '<sz val="' . ($st['size'] ?? 11) . '"/>' . (isset($st['color']) ? '<color rgb="FF' . $st['color'] . '"/>' : '')
				. '<name val="' . self::esc($st['font'] ?? 'Calibri') . '"/><family val="2"/></font>';
			$fontId = array_search($font, $fonts, true);
			if ($fontId === false) { $fonts[] = $font; $fontId = count($fonts) - 1; }
			$fillId = 0;
			if (isset($st['bg'])) {
				$fill = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $st['bg'] . '"/><bgColor indexed="64"/></patternFill></fill>';
				$fillId = array_search($fill, $fills, true);
				if ($fillId === false) { $fills[] = $fill; $fillId = count($fills) - 1; }
			}
			$borderId = 0;
			if (isset($st['border'])) {
				$b = '<border>' . implode('', array_map(static fn($side) => '<' . $side . ' style="' . $st['border'] . '"><color auto="1"/></' . $side . '>', ['left', 'right', 'top', 'bottom'])) . '<diagonal/></border>';
				$borderId = array_search($b, $borders, true);
				if ($borderId === false) { $borders[] = $b; $borderId = count($borders) - 1; }
			}
			$fmt = $st['format'] ?? ($kind === 'date' ? $this->dateFormat : ($kind === 'datetime' ? $this->dateTimeFormat : ''));
			$numFmtId = 0;
			if ($fmt !== '') {
				if (isset(self::BUILTIN_FORMATS[$fmt])) $numFmtId = self::BUILTIN_FORMATS[$fmt];
				else {
					$numFmtId = array_search($fmt, $numFmts, true);
					if ($numFmtId === false) { $numFmtId = 164 + count($numFmts); $numFmts[$numFmtId] = $fmt; }
				}
			} elseif ($kind === 'date') $numFmtId = 14;
			elseif ($kind === 'datetime') $numFmtId = 22;
			$align = '';
			if (isset($st['align']) || isset($st['valign']) || !empty($st['wrap'])) {
				$align = '<alignment' . (isset($st['align']) ? ' horizontal="' . $st['align'] . '"' : '')
					. (isset($st['valign']) ? ' vertical="' . ['top' => 'top', 'middle' => 'center', 'bottom' => 'bottom'][$st['valign']] . '"' : '')
					. (!empty($st['wrap']) ? ' wrapText="1"' : '') . '/>';
			}
			$xf = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
				. ($numFmtId ? ' applyNumberFormat="1"' : '') . ($fontId ? ' applyFont="1"' : '') . ($fillId ? ' applyFill="1"' : '') . ($borderId ? ' applyBorder="1"' : '')
				. ($align !== '' ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
			$at = array_search($xf, $xfs, true);
			if ($at === false) { $xfs[] = $xf; $at = count($xfs) - 1; }
			$this->xfIndex[$key] = (int)$at;
		}
		$nf = '';
		foreach ($numFmts as $id => $code) $nf .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::esc($code) . '"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. ($nf !== '' ? '<numFmts count="' . count($numFmts) . '">' . $nf . '</numFmts>' : '')
			. '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
			. '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
			. '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	private function xf(array $st, string $kind): int
	{
		return $this->xfIndex[NuXlsxSheet::styleKey($st) . '|' . $kind] ?? 0;
	}

	private function workbookXml(): string
	{
		$sheets = $names = '';
		foreach ($this->sheets as $i => $s) {
			$sheets .= '<sheet name="' . self::esc($s->name()) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
			if (($f = $s->filterRange()) !== '') {
				$names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . self::esc(str_replace("'", "''", $s->name())) . '\'!' . self::absRange($f) . '</definedName>';
			}
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<bookViews><workbookView/></bookViews><sheets>' . $sheets . '</sheets>' . ($names !== '' ? '<definedNames>' . $names . '</definedNames>' : '') . '</workbook>';
	}

	private static function absRange(string $r): string
	{
		[$r1, $c1, $r2, $c2] = self::range($r);
		return '$' . self::colName($c1) . '$' . $r1 . ':$' . self::colName($c2) . '$' . $r2;
	}

	private function workbookRels(): string
	{
		$out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		$n = count($this->sheets);
		foreach ($this->sheets as $i => $s) $out .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
		$out .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		$out .= '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
		return $out . '</Relationships>';
	}

	private function contentTypes(): string
	{
		$out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
		foreach ($this->sheets as $i => $s) $out .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		return $out . '</Types>';
	}

	private function coreXml(): string
	{
		$now = gmdate('Y-m-d\TH:i:s\Z');
		$p = $this->props;
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. ($p['title'] !== '' ? '<dc:title>' . self::esc($p['title']) . '</dc:title>' : '')
			. ($p['subject'] !== '' ? '<dc:subject>' . self::esc($p['subject']) . '</dc:subject>' : '')
			. ($p['author'] !== '' ? '<dc:creator>' . self::esc($p['author']) . '</dc:creator>' : '')
			. ($p['keywords'] !== '' ? '<cp:keywords>' . self::esc($p['keywords']) . '</cp:keywords>' : '')
			. ($p['description'] !== '' ? '<dc:description>' . self::esc($p['description']) . '</dc:description>' : '')
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
			. '</cp:coreProperties>';
	}
}

/** One sheet of a NuXlsx workbook – rows are written one after the other; all methods return the sheet (chainable). */
final class NuXlsxSheet
{
	/** @var array<int, array<int, array{0: mixed, 1: array, 2: bool}>> row => col => [value, style, formula] */
	private array $cells = [];
	private int $row = 0;
	/** @var array<int, float> col => width */
	private array $widths = [];
	private bool $auto = true;
	/** @var list<array{0: array, 1: array}> [range, style] */
	private array $rangeStyles = [];
	private array $merges = [];
	private string $freeze = '';
	private string $filter = '';
	private bool $rtl = false;
	private bool $numbers = false;
	/** @var array<int, float> row => height */
	private array $heights = [];

	public function __construct(private string $name) {}

	public function name(): string { return $this->name; }

	/** The last written row (0 = none yet). */
	public function lastRow(): int { return $this->row; }

	/** A header row: bold (or $style), the sheet is frozen below it and gets an autofilter over it. */
	public function header(array $titles, array $style = ['bold' => true]): self
	{
		$this->row(array_values($titles), $style);
		$this->freeze = 'A' . ($this->row + 1);
		if ($titles) $this->filter = 'A' . $this->row . ':' . NuXlsx::colName(count($titles)) . $this->row;
		return $this;
	}

	/** One row; values: int / float / bool / DateTimeInterface / string / null, or NuXlsx::cell() / NuXlsx::formula(). */
	public function row(array $values, array $style = []): self
	{
		if ($this->row >= NuXlsx::MAX_ROWS) throw new OverflowException('NuXlsx: more than ' . NuXlsx::MAX_ROWS . ' rows');
		if (count($values) > NuXlsx::MAX_COLS) throw new OverflowException('NuXlsx: more than ' . NuXlsx::MAX_COLS . ' columns');
		$this->row++;
		$st = NuXlsx::cleanStyle($style);
		$c = 0;
		foreach ($values as $v) {
			$c++;
			if ($v instanceof NuXlsxCell) $this->cells[$this->row][$c] = [$v->value, $v->style + $st, $v->formula];
			elseif ($v !== null && $v !== '') $this->cells[$this->row][$c] = [$v, $st, false];
			elseif ($st) $this->cells[$this->row][$c] = [null, $st, false];   // empty but styled (fill, border)
		}
		return $this;
	}

	/** Several rows (iterable of arrays). */
	public function rows(iterable $rows, array $style = []): self
	{
		foreach ($rows as $r) $this->row(is_array($r) ? array_values($r) : [$r], $style);
		return $this;
	}

	public function emptyRow(int $n = 1): self
	{
		$this->row = min(NuXlsx::MAX_ROWS, $this->row + max(0, $n));
		return $this;
	}

	/** A style for a range of written cells, e.g. style('B2:B20', ['format' => '#,##0.00']) – later calls win. */
	public function style(string $range, array $style): self
	{
		$this->rangeStyles[] = [NuXlsx::range($range), NuXlsx::cleanStyle($style)];
		return $this;
	}

	public function bold(string $range): self { return $this->style($range, ['bold' => true]); }

	/** Column width in characters: width('A', 30) or width(2, 12). */
	public function width(string|int $col, float $width): self
	{
		$this->widths[NuXlsx::colNumber($col)] = max(0.5, min(255.0, $width));
		return $this;
	}

	/** Widths of the first columns: widths([30, 12, 12]). */
	public function widths(array $widths): self
	{
		$i = 0;
		foreach ($widths as $w) { $i++; if ($w !== null) $this->width($i, (float)$w); }
		return $this;
	}

	/** Width from the content for columns without width() (default on). */
	public function autoWidth(bool $on = true): self { $this->auto = $on; return $this; }

	public function height(int $row, float $points): self
	{
		if ($row < 1 || $row > NuXlsx::MAX_ROWS) throw new InvalidArgumentException('NuXlsx: row out of range');
		$this->heights[$row] = max(1.0, min(409.0, $points));
		return $this;
	}

	public function merge(string $range): self { $r = NuXlsx::range($range); $this->merges[] = $r; return $this; }

	/** Freeze the rows above and the columns left of a cell: freeze('A2') = header row, freeze('B2') = also column A; '' = none. */
	public function freeze(string $cell): self
	{
		if ($cell !== '') { $r = NuXlsx::range($cell); if ($r[0] !== $r[2] || $r[1] !== $r[3]) throw new InvalidArgumentException('NuXlsx: freeze needs one cell'); }
		$this->freeze = strtoupper($cell);
		return $this;
	}

	/** Autofilter over a range (the header row and its columns), '' = none. */
	public function filter(string $range): self
	{
		if ($range !== '') NuXlsx::range($range);
		$this->filter = strtoupper($range);
		return $this;
	}

	public function rightToLeft(bool $on = true): self { $this->rtl = $on; return $this; }

	/** Strings that are plain numbers ("12", "-3.5") become numbers – for exports of text values (default off). */
	public function numbersFromText(bool $on = true): self { $this->numbers = $on; return $this; }

	/** @internal */
	public function filterRange(): string { return $this->filter; }

	/** @internal style key => [style, kind] of every cell (for styles.xml). */
	public function usedStyles(): array
	{
		$out = [];
		foreach ($this->cells as $r => $cols) foreach ($cols as $c => $cell) {
			$v = $this->value($cell[0]);
			$st = $this->styleAt($r, $c, $cell[1], $v);
			$kind = self::kind($v);
			$out[self::styleKey($st) . '|' . $kind] = [$st, $kind];
		}
		return $out;
	}

	/** @internal */
	public static function styleKey(array $st): string
	{
		return $st ? json_encode($st) ?: '' : '';
	}

	/** @internal the sheet XML */
	public function xml(NuXlsxStrings $strings, callable $xf): string
	{
		ksort($this->cells);
		$maxCol = 0;
		$len = [];
		$data = '';
		foreach ($this->cells as $r => $cols) {
			ksort($cols);
			$data .= '<row r="' . $r . '"' . (isset($this->heights[$r]) ? ' ht="' . $this->heights[$r] . '" customHeight="1"' : '') . '>';
			foreach ($cols as $c => [$raw, $own, $isFormula]) {
				$maxCol = max($maxCol, $c);
				$ref = NuXlsx::colName($c) . $r;
				$v = $this->value($raw);
				$kind = self::kind($v);
				$st = $this->styleAt($r, $c, $own, $v);
				$s = $xf($st, $kind);
				$sa = $s ? ' s="' . $s . '"' : '';
				if ($isFormula) {
					$data .= '<c r="' . $ref . '"' . $sa . '><f>' . NuXlsx::esc((string)$raw) . '</f></c>';
					continue;
				}
				switch ($kind) {
					case 'empty': $data .= '<c r="' . $ref . '"' . $sa . '/>'; break;
					case 'bool': $data .= '<c r="' . $ref . '"' . $sa . ' t="b"><v>' . ($v ? 1 : 0) . '</v></c>'; $len[$c] = max($len[$c] ?? 0, 5); break;
					case 'number':
						$data .= '<c r="' . $ref . '"' . $sa . '><v>' . self::num($v) . '</v></c>';
						$len[$c] = max($len[$c] ?? 0, strlen(self::num($v)) + 2);
						break;
					case 'date': case 'datetime':
						$data .= '<c r="' . $ref . '"' . $sa . '><v>' . self::num(NuXlsx::serial($v)) . '</v></c>';
						$len[$c] = max($len[$c] ?? 0, $kind === 'date' ? 11 : 17);
						break;
					default:
						$t = mb_substr((string)$v, 0, NuXlsx::MAX_TEXT);
						$data .= '<c r="' . $ref . '"' . $sa . ' t="s"><v>' . $strings->id($t) . '</v></c>';
						$longest = max(array_map('mb_strlen', explode("\n", $t)));
						$len[$c] = max($len[$c] ?? 0, (int)ceil($longest * (!empty($st['bold']) ? 1.1 : 1.0)) + 2);
				}
			}
			$data .= '</row>';
		}
		$cols = '';
		$widths = $this->widths;
		if ($this->auto) foreach ($len as $c => $l) if (!isset($widths[$c])) $widths[$c] = (float)max(6, min(60, $l));
		ksort($widths);
		foreach ($widths as $c => $w) $cols .= '<col min="' . $c . '" max="' . $c . '" width="' . round($w, 2) . '" customWidth="1"/>';
		$view = '<sheetView workbookViewId="0"' . ($this->rtl ? ' rightToLeft="1"' : '') . '>';
		if ($this->freeze !== '' && $this->freeze !== 'A1') {
			[$fr, $fc] = NuXlsx::range($this->freeze);
			$x = $fc - 1; $y = $fr - 1;
			$pane = $x && $y ? 'bottomRight' : ($y ? 'bottomLeft' : 'topRight');
			$view .= '<pane' . ($x ? ' xSplit="' . $x . '"' : '') . ($y ? ' ySplit="' . $y . '"' : '') . ' topLeftCell="' . $this->freeze . '" activePane="' . $pane . '" state="frozen"/>'
				. '<selection pane="' . $pane . '" activeCell="' . $this->freeze . '" sqref="' . $this->freeze . '"/>';
		}
		$view .= '</sheetView>';
		$dim = $this->cells ? 'A1:' . NuXlsx::colName(max(1, $maxCol)) . max(1, (int)max(array_keys($this->cells))) : 'A1';
		$merge = '';
		foreach ($this->merges as [$r1, $c1, $r2, $c2]) $merge .= '<mergeCell ref="' . NuXlsx::colName($c1) . $r1 . ':' . NuXlsx::colName($c2) . $r2 . '"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<dimension ref="' . $dim . '"/><sheetViews>' . $view . '</sheetViews><sheetFormatPr defaultRowHeight="15"/>'
			. ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
			. '<sheetData>' . $data . '</sheetData>'
			. ($this->filter !== '' ? '<autoFilter ref="' . $this->filter . '"/>' : '')
			. ($merge !== '' ? '<mergeCells count="' . count($this->merges) . '">' . $merge . '</mergeCells>' : '')
			. '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
			. '</worksheet>';
	}

	/** Style of a cell: its own (and its row's), range styles over it; text with line breaks wraps unless 'wrap' is set. */
	private function styleAt(int $r, int $c, array $own, mixed $v = null): array
	{
		$st = $own;
		foreach ($this->rangeStyles as [[$r1, $c1, $r2, $c2], $rs]) if ($r >= $r1 && $r <= $r2 && $c >= $c1 && $c <= $c2) $st = $rs + $st;
		if (is_string($v) && str_contains($v, "\n") && !isset($st['wrap'])) $st['wrap'] = true;
		ksort($st);
		return $st;
	}

	private function value(mixed $v): mixed
	{
		if ($this->numbers && is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d{1,10})?$/', $v)) return str_contains($v, ".") ? (float)$v : (int)$v;
		return $v;
	}

	private static function kind(mixed $v): string
	{
		if ($v === null || $v === '') return 'empty';
		if (is_bool($v)) return 'bool';
		if (is_int($v) || (is_float($v) && is_finite($v))) return 'number';
		if ($v instanceof DateTimeInterface) return $v->format('H:i:s') === '00:00:00' ? 'date' : 'datetime';
		return 'text';
	}

	private static function num(int|float $v): string
	{
		if (is_int($v)) return (string)$v;
		$s = rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
		return $s === '-0' ? '0' : $s;
	}
}

/** A cell with its own style, or a formula (NuXlsx::cell(), NuXlsx::formula()). */
final class NuXlsxCell
{
	public function __construct(public readonly mixed $value, public readonly array $style = [], public readonly bool $formula = false) {}
}

/** @internal shared strings of a workbook */
final class NuXlsxStrings
{
	private array $ids = [];
	private int $count = 0;

	public function id(string $s): int
	{
		$this->count++;
		return $this->ids[$s] ??= count($this->ids);
	}

	public function xml(): string
	{
		$out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $this->count . '" uniqueCount="' . count($this->ids) . '">';
		foreach (array_keys($this->ids) as $s) $out .= '<si><t xml:space="preserve">' . NuXlsx::esc((string)$s) . '</t></si>';
		return $out . '</sst>';
	}
}
