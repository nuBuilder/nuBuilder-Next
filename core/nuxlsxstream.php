<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Streaming .xlsx writer (0.8.758, Data export): rows go straight into a temporary sheet file while they are read from the database, so
 * memory stays flat for any number of rows. Strings are inline (no shared string table), styles are one per column (number / date
 * format) plus the header. A sheet that reaches Excel's row limit continues on "<name> (2)". NuXlsx (core/nuxlsx.php) stays the
 * full-featured in-memory writer for procedures; this one only does tables.
 *
 *   $w = new NuXlsxStream();
 *   $w->sheet('Orders', ['Number', 'Date', 'Total'], ['', 'dd.mm.yyyy', '#,##0.00']);   // formats per column ('' = General)
 *   $w->row(['A-1', new DateTimeImmutable('2026-01-02'), 12.5]);                         // int / float / bool / DateTimeInterface / string / null
 *   $w->download('orders.xlsx');  or  $w->save($path);
 */
final class NuXlsxStream
{
	public const MAX_ROWS = 1048576;

	/** @var list<array{name: string, file: string, rows: int, cols: int, widths: array<int, int>}> */
	private array $sheets = [];
	/** @var resource|null */
	private $fh = null;
	private int $cur = -1;
	/** @var list<int> style index per column of the current sheet (0 = default) */
	private array $colStyles = [];
	private array $head = [];
	private array $formats = [];
	/** numFmt string => xf index */
	private array $xf = [];
	private array $numFmts = [];
	private string $title = '';
	private string $baseName = '';
	private int $part = 1;

	public function __destruct()
	{
		$this->closeSheet();
		$this->cleanup();
	}

	public function __construct(string $title = '')
	{
		if (!class_exists('ZipArchive')) throw new RuntimeException('NuXlsxStream: the PHP extension zip is missing');
		$this->title = $title;
		$this->xf[''] = 0;
	}

	/** Starts a sheet: header titles and a number format per column ('' = General, 'text' = @). */
	public function sheet(string $name, array $head, array $formats = []): self
	{
		$this->closeSheet();
		$this->baseName = $name;
		$this->part = 1;
		$this->head = array_values(array_map('strval', $head));
		$this->formats = array_values($formats);
		$this->openSheet($this->uniqueName($name));
		return $this;
	}

	public function row(array $values): self
	{
		if ($this->cur < 0) $this->sheet('Sheet1', []);
		$s = &$this->sheets[$this->cur];
		if ($s['rows'] >= self::MAX_ROWS) {                       // Excel's limit: continue on the next part
			$this->closeSheet();
			$this->part++;
			$this->openSheet($this->uniqueName($this->baseName . ' (' . $this->part . ')'));
			$s = &$this->sheets[$this->cur];
		}
		$s['rows']++;
		$r = $s['rows'];
		$xml = '<row r="' . $r . '">';
		$c = 0;
		foreach ($values as $v) {
			$c++;
			$st = $this->colStyles[$c - 1] ?? 0;
			$cell = $this->cell($v, $c, $r, $st, $s['widths']);
			if ($cell !== '') $xml .= $cell;
		}
		$s['cols'] = max($s['cols'], $c);
		fwrite($this->fh, $xml . '</row>');
		return $this;
	}

	/** Rows of the current sheet (all parts of it). */
	public function rows(): int
	{
		$n = 0;
		foreach ($this->sheets as $s) if ($s['name'] === $this->baseName || str_starts_with($s['name'], $this->baseName . ' (')) $n += $s['rows'] - ($this->head ? 1 : 0);
		return max(0, $n);
	}

	public function sheetNames(): array { return array_column($this->sheets, 'name'); }

	public function save(string $file): void
	{
		$this->closeSheet();
		$tmp = $file . '.part';
		try {
			$this->writeZip($tmp);
			if (!@rename($tmp, $file)) throw new RuntimeException('NuXlsxStream: cannot write ' . basename($file));
		} finally {
			if (is_file($tmp)) @unlink($tmp);
			$this->cleanup();
		}
	}

	/** Builds the file and sends it (headers + body); ends the request. */
	public function download(string $filename): never
	{
		$tmp = tempnam(sys_get_temp_dir(), 'nuxls');
		if ($tmp === false) throw new RuntimeException('NuXlsxStream: no temporary file');
		try {
			$this->save($tmp);
		} catch (Throwable $e) {
			@unlink($tmp);
			throw $e;
		}
		NuDataExport::sendFile($tmp, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');   // unlinks the file
	}

	// ================================================================= internals

	private function openSheet(string $name): void
	{
		$file = tempnam(sys_get_temp_dir(), 'nuxsh');
		if ($file === false) throw new RuntimeException('NuXlsxStream: no temporary file');
		$fh = fopen($file, 'w');
		if ($fh === false) throw new RuntimeException('NuXlsxStream: cannot write ' . $file);
		$this->fh = $fh;
		$this->sheets[] = ['name' => $name, 'file' => $file, 'rows' => 0, 'cols' => 0, 'widths' => []];
		$this->cur = count($this->sheets) - 1;
		$this->colStyles = [];
		foreach ($this->formats as $i => $f) $this->colStyles[$i] = $this->styleFor((string)$f);
		fwrite($fh, '<sheetData>');
		if ($this->head) {
			$s = &$this->sheets[$this->cur];
			$s['rows'] = 1;
			$xml = '<row r="1">';
			foreach ($this->head as $i => $t) $xml .= $this->cell($t, $i + 1, 1, 1, $s['widths'], true);
			$s['cols'] = count($this->head);
			fwrite($fh, $xml . '</row>');
		}
	}

	private function closeSheet(): void
	{
		if ($this->cur < 0 || $this->fh === null) return;
		fwrite($this->fh, '</sheetData>');
		fclose($this->fh);
		$this->fh = null;
	}

	/** xf index of a number format (1 = header). */
	private function styleFor(string $fmt): int
	{
		if ($fmt === '' || $fmt === 'General') return 0;
		if ($fmt === 'text') $fmt = '@';
		if (isset($this->xf[$fmt])) return $this->xf[$fmt];
		$this->numFmts[] = $fmt;
		return $this->xf[$fmt] = count($this->xf) + 1;    // 0 default, 1 header, then the formats in order
	}

	private function cell(mixed $v, int $c, int $r, int $st, array &$widths, bool $header = false): string
	{
		if ($v === null || $v === '') return '';
		$ref = NuXlsx::colName($c) . $r;
		$s = $st ? ' s="' . $st . '"' : '';
		if (is_bool($v)) { $w = 6; $x = '<c r="' . $ref . '"' . $s . ' t="b"><v>' . ($v ? 1 : 0) . '</v></c>'; }
		elseif (is_int($v) || is_float($v)) {
			$n = is_int($v) ? (string)$v : (is_finite($v) ? ($v == 0.0 ? '0' : sprintf('%.15G', $v)) : '');
			if ($n === '') return '';
			$w = strlen($n) + 2;
			$x = '<c r="' . $ref . '"' . $s . '><v>' . $n . '</v></c>';
		} elseif ($v instanceof DateTimeInterface) {
			$day = $v->format('Y-m-d');
			if ($day < '1900-03-01' || strlen($day) !== 10) return $this->cell($v->format($v->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s'), $c, $r, 0, $widths);
			$w = $v->format('H:i:s') === '00:00:00' ? 12 : 18;
			$x = '<c r="' . $ref . '"' . ($st ? $s : ' s="' . $this->dateStyle($w === 12) . '"') . '><v>' . sprintf('%.15G', NuXlsx::serial($v)) . '</v></c>';
		} else {
			$t = mb_substr(str_replace(["\r\n", "\r"], "\n", (string)$v), 0, NuXlsx::MAX_TEXT);
			$esc = NuXlsx::esc(str_contains($t, '_x') ? (string)preg_replace('/_(x[0-9A-Fa-f]{4}_)/', '_x005F_$1', $t) : $t);
			$w = min(60, mb_strwidth(strtok($t, "\n") ?: '', 'UTF-8') + 2);
			$x = '<c r="' . $ref . '"' . ($header ? ' s="1"' : $s) . ' t="inlineStr"><is><t xml:space="preserve">' . $esc . '</t></is></c>';
		}
		if ($header) $w += 2;
		if ($w > ($widths[$c] ?? 0)) $widths[$c] = $w;
		return $x;
	}

	private function dateStyle(bool $dateOnly): int
	{
		return $this->styleFor($dateOnly ? 'yyyy-mm-dd' : 'yyyy-mm-dd hh:mm:ss');
	}

	private function uniqueName(string $name): string
	{
		$n = trim((string)preg_replace('/[:\\\\\/?*\[\]]/', ' ', $name), " '");
		$n = mb_substr($n !== '' ? $n : 'Sheet', 0, 31);
		$taken = array_map(static fn($s) => mb_strtolower($s['name']), $this->sheets);
		$taken[] = 'history';
		$base = $n; $i = 2;
		while (in_array(mb_strtolower($n), $taken, true)) $n = mb_substr($base, 0, 31 - strlen((string)$i) - 1) . ' ' . $i++;
		return $n;
	}

	private function cleanup(): void
	{
		foreach ($this->sheets as $s) if (is_file($s['file'])) @unlink($s['file']);
	}

	private function writeZip(string $path): void
	{
		if (!$this->sheets) { $this->sheet('Sheet1', []); $this->closeSheet(); }
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('NuXlsxStream: cannot create the zip');
		$n = count($this->sheets);
		$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
		$wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>';
		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ($this->sheets as $i => $s) {
			$k = $i + 1;
			$ct .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb .= '<sheet name="' . NuXlsx::esc($s['name']) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
			$rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
			$cols = '';
			ksort($s['widths']);
			foreach ($s['widths'] as $c => $w) $cols .= '<col min="' . $c . '" max="' . $c . '" width="' . max(6, $w) . '" customWidth="1"/>';
			$last = NuXlsx::colName(max(1, $s['cols'])) . max(1, $s['rows']);
			$filter = $this->head && $s['rows'] > 0 ? '<autoFilter ref="A1:' . $last . '"/>' : '';
			$pane = $this->head ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/>' : '';
			$pre = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
				. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
				. '<dimension ref="A1:' . $last . '"/><sheetViews><sheetView workbookViewId="0"' . ($i === 0 ? ' tabSelected="1"' : '') . '>' . $pane . '</sheetView></sheetViews>'
				. '<sheetFormatPr defaultRowHeight="15"/>' . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '');
			$post = $filter . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
			// head + data file + tail without reading the data into memory: a joined temp file
			$fh = fopen($s['file'], 'a');
			if ($fh === false) throw new RuntimeException('NuXlsxStream: cannot finish a sheet');
			fwrite($fh, $post);
			fclose($fh);
			$joined = $s['file'] . '.x';
			$out = fopen($joined, 'w');
			$in = fopen($s['file'], 'r');
			if ($out === false || $in === false) throw new RuntimeException('NuXlsxStream: cannot finish a sheet');
			fwrite($out, $pre);
			stream_copy_to_stream($in, $out);
			fclose($in); fclose($out);
			@unlink($s['file']);
			$this->sheets[$i]['file'] = $joined;
			$zip->addFile($joined, 'xl/worksheets/sheet' . $k . '.xml');
		}
		$ct .= '</Types>';
		$wb .= '</sheets></workbook>';
		$rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
		$zip->addFromString('[Content_Types].xml', $ct);
		$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
		$zip->addFromString('xl/workbook.xml', $wb);
		$zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
		$zip->addFromString('xl/styles.xml', $this->stylesXml());
		$now = gmdate('Y-m-d\TH:i:s\Z');
		$zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. ($this->title !== '' ? '<dc:title>' . NuXlsx::esc($this->title) . '</dc:title>' : '')
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>');
		$zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>nuBuilder Next</Application></Properties>');
		if (!$zip->close()) throw new RuntimeException('NuXlsxStream: cannot write the zip');
	}

	private function stylesXml(): string
	{
		$builtin = ['0' => 1, '0.00' => 2, '#,##0' => 3, '#,##0.00' => 4, '0%' => 9, '0.00%' => 10, '@' => 49, 'yyyy-mm-dd' => 0, 'yyyy-mm-dd hh:mm:ss' => 0];
		$numFmts = '';
		$xfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>';
		$custom = 164;
		$nCustom = 0;
		foreach ($this->numFmts as $f) {
			if (isset($builtin[$f]) && $builtin[$f] > 0) $id = $builtin[$f];
			else { $id = $custom++; $nCustom++; $numFmts .= '<numFmt numFmtId="' . $id . '" formatCode="' . NuXlsx::esc($f) . '"/>'; }
			$xfs .= '<xf numFmtId="' . $id . '" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. ($numFmts !== '' ? '<numFmts count="' . $nCustom . '">' . $numFmts . '</numFmts>' : '')
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
			. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFE7EDF5"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="' . (2 + count($this->numFmts)) . '">' . $xfs . '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
	}
}
