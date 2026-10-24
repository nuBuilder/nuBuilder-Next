<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * The report writer – a port of nuBuilder's core/nurunpdf.php (nuBuildReport, nuSECTION, nuPrintReport), same layout rules:
 * sections are cut over pages, fields grow by lines (minimum / maximum rows), page header and footer on every page, group headers and
 * footers when a sort field changes, SUM(field) and AVERAGE(a,b) per group, conditional formatting (#B#rrggbb| / #F#rrggbb| in front of a
 * value), labels with #page#, #pages#, #code#, #description#, date parts and hash variables, images, "KEEP EXACT HEIGHT" boxes.
 * Differences: the data is sorted and summed in PHP (so it works on MSSQL too), text widths count UTF-8 characters correctly.
 */
final class NuReportEngine
{
	private TCPDF $pdf;
	private object $lay;
	/** @var array<int, array> */
	private array $rows;
	private array $hash;
	/** @var array<int, string> sort fields of the used groups (nuBuilder's $groups) */
	private array $groups = [];
	/** @var array<int, object> the printed sections (nuBuilder's $GLOBALS['nu_report']) */
	public array $out = [];
	private string $fontDir = '';

	public function __construct(object $layout, array $rows, array $hash, private string $code = '', private string $description = '')
	{
		$this->lay = self::toMM($layout);
		$this->rows = $rows;
		$this->hash = $hash;
		$this->pdf = new NuTCPDF($this->lay->orientation, 'mm', $this->lay->paper, true, 'UTF-8', false);
		$this->pdf->setAutoPageBreak(true);
		$this->pdf->setPrintHeader(false);
		$this->pdf->setPrintFooter(false);
		$this->pdf->setMargins(1, 1, 1);
		$this->pdf->SetCreator('nuBuilder Next');
		$this->pdf->SetTitle($description !== '' ? $description : $code);
		$this->fontDir = (string)NuReport::tcpdfDir() . '/fonts/';
	}

	/** nuSetPixelsToMM: heights, positions and widths × 0.25 (font sizes stay points). */
	private static function toMM(object $l): object
	{
		$l = json_decode((string)json_encode($l));
		foreach ($l->groups as $g) foreach ($g->sections as $s) {
			$s->height = (float)$s->height * .25;
			foreach ($s->objects as $o) foreach (['height', 'left', 'top', 'width'] as $k) $o->$k = (float)$o->$k * .25;
		}
		return $l;
	}

	public function run(): string
	{
		$this->build();
		$pages = 0;
		foreach ($this->out as $s) if ($s->sectionTop == 0) $pages++;
		$this->hash['nu_pages'] = (string)$pages;
		$this->replaceLabelHash();
		$this->printReport();
		return $this->pdf->Output('nureport.pdf', 'S');
	}

	// ----------------------------------------------------------------- nuBuildReport

	private function build(): void
	{
		$sortBy = [];
		foreach (NuReport::SORT_GROUPS as $i) {
			$f = (string)$this->lay->groups[$i]->sortField;
			if ($f !== '') { $this->groups[] = $f; $sortBy[] = [$f, $this->lay->groups[$i]->sortBy === 'd' ? -1 : 1]; }
		}
		if ($sortBy) {
			$idx = array_keys($this->rows);
			usort($idx, function ($a, $b) use ($sortBy) {
				foreach ($sortBy as [$f, $dir]) {
					$c = self::compare($this->rows[$a][$f] ?? null, $this->rows[$b][$f] ?? null);
					if ($c !== 0) return $c * $dir;
				}
				return $a <=> $b;
			});
			$this->rows = array_map(fn($i) => $this->rows[$i], $idx);
		}

		$row = $this->rows[0] ?? [];
		$top = $this->section($row, 1, 0, 0);                                   // report header
		$top = $this->section($row, 2, 0, $top);                                // page header
		$value = [];
		foreach ($this->groups as $g => $f) {                                   // first group headers
			$top = $this->section($row, 3 + $g, 0, $top);
			$value[$f] = $row[$f] ?? null;
		}
		$first = true;
		$last = [];
		foreach ($this->rows as $row) {
			if (!$first) {
				$backTo = count($this->groups);
				foreach ($this->groups as $g => $f) if (!self::same($value[$f] ?? null, $row[$f] ?? null)) { $backTo = $g; break; }
				for ($g = count($this->groups) - 1; $g >= $backTo; $g--) {        // footers of the groups that changed
					$top = $this->section($last, 3 + $g, 1, $top);
					$value[$this->groups[$g]] = $row[$this->groups[$g]] ?? null;
				}
				for ($g = $backTo, $nG = count($this->groups); $g < $nG; $g++) {   // and their new headers
					$top = $this->section($row, 3 + $g, 0, $top);
					$value[$this->groups[$g]] = $row[$this->groups[$g]] ?? null;
				}
			}
			$top = $this->section($row, 0, 0, $top);                             // detail
			$last = $row;
			$first = false;
		}
		for ($g = count($this->groups) - 1; $g > -1; $g--) {                     // last group footers
			$top = $this->section($last, 3 + $g, 1, $top, $g === 0);
		}
		$this->section($last, 1, 1, $top);                                       // report footer
	}

	/** MySQL-like ORDER BY: NULL first, numbers as numbers, text case-insensitive. */
	private static function compare(mixed $a, mixed $b): int
	{
		if ($a === null || $b === null) return ($a === null ? 0 : 1) - ($b === null ? 0 : 1);
		if (is_numeric($a) && is_numeric($b)) return (float)$a <=> (float)$b;
		return strcmp(mb_strtolower((string)$a), mb_strtolower((string)$b));
	}

	private static function same(mixed $a, mixed $b): bool
	{
		if ($a === null || $b === null) return $a === $b || (string)$a === (string)$b;
		return is_numeric($a) && is_numeric($b) ? (float)$a == (float)$b : mb_strtolower((string)$a) === mb_strtolower((string)$b);
	}

	private function section(array $row, int $group, int $section, float $top, bool $lastGroupFooter = false): float
	{
		$s = new NuReportSection($this, $this->pdf, $row, $this->lay, $group, $section, $top, $this->fontDir);
		if ($lastGroupFooter && count($this->lay->groups[1]->sections[1]->objects) === 0) $s->noPageBreak();   // nuRemovePageBreak
		$next = $s->build();
		array_push($this->out, ...$s->sections);
		return $next;
	}

	// ----------------------------------------------------------------- values

	/** nuGetFieldValue: a column, SUM(field) or AVERAGE(a,b) – sums over the rows of the current group, then the format. */
	public function fieldValue(object $o, array $row, int $group): string
	{
		$name = (string)$o->fieldName;
		$upper = strtoupper($name);
		if (str_starts_with($upper, 'SUM(') || str_starts_with($upper, 'AVERAGE(')) {
			$isAvg = str_starts_with($upper, 'AVERAGE(');
			$args = array_map('trim', explode(',', substr($name, $isAvg ? 8 : 4, -1)));
			$filter = [];
			for ($i = 3; $i <= $group && $i <= 7; $i++) {
				$f = (string)$this->lay->groups[$i]->sortField;
				if ($f !== '') $filter[$f] = $row[$f] ?? null;
			}
			$a = $b = 0.0;
			$n = 0;
			foreach ($this->rows as $r) {
				foreach ($filter as $f => $v) if (!self::same($r[$f] ?? null, $v)) continue 2;
				$n++;
				$a += (float)($r[$args[0]] ?? 0);
				if ($isAvg) $b += (float)($r[$args[1] ?? ''] ?? 0);
			}
			if ($n === 0) $value = 0;
			elseif ($isAvg) $value = $b == 0 ? 0 : $a / $b;
			else $value = $a;
			$value = (string)(is_float($value) && floor($value) == $value && abs($value) < 1e15 ? (string)(int)$value : $value);
		} else {
			$value = array_key_exists($name, $row) ? self::stripFormatting((string)($row[$name] ?? ''))[0] : '';
		}
		if ((string)$o->format !== '') $value = nuAddFormatting($value, (string)$o->format);
		return (string)$value;
	}

	/** Conditional formatting "#B#ff0000|#F#ffffff|value" → [value, ['B' => …, 'F' => …]]. */
	public static function stripFormatting(string $v): array
	{
		$f = [];
		while (strlen($v) >= 10 && $v[0] === '#' && $v[2] === '#' && $v[9] === '|') {
			$f[strtoupper($v[1])] = substr($v, 3, 6);
			$v = substr($v, 10);
		}
		return [$v, $f];
	}

	// ----------------------------------------------------------------- printing (nuReplaceLabelHashVariables, nuPrintReport)

	private function replaceLabelHash(): void
	{
		foreach ($this->out as $s) foreach ($s->objects as $o) {
			if ($this->prop($o->id)?->objectType !== 'label') continue;
			foreach ($o->lines as $i => $l) $o->lines[$i] = NuHash::replace((string)$l, $this->hash, NuHash::RAW);
		}
	}

	private ?array $props = null;

	public function prop(string $id): ?object
	{
		if ($this->props === null) {
			$this->props = [];
			foreach ($this->lay->groups as $g) foreach ($g->sections as $s) foreach ($s->objects as $o) $this->props[(string)$o->id] ??= $o;
		}
		return $this->props[$id] ?? null;
	}

	private function printReport(): void
	{
		$last = 10000;
		$page = 0;
		foreach ($this->out as $s) {
			if ($last > $s->sectionTop) $page++;
			$last = $s->sectionTop;
			foreach ($s->objects as $o) {
				if ($this->prop($o->id)?->objectType === 'label' && isset($o->lines[0])) $o->lines[0] = str_replace('#page#', (string)$page, (string)$o->lines[0]);
			}
		}
		$now = time();
		$repl = ['#pages#' => (string)$page, '#description#' => $this->description, '#code#' => $this->code, '#year#' => date('y', $now), '#month#' => date('m', $now),
			'#day#' => date('d', $now), '#hour#' => date('h', $now), '#hour24#' => date('H', $now), '#minute#' => date('i', $now), '#second#' => date('s', $now)];
		foreach ($this->out as $s) foreach ($s->objects as $o) {
			if ($this->prop($o->id)?->objectType === 'label' && isset($o->lines[0])) $o->lines[0] = strtr((string)$o->lines[0], $repl);
		}

		$last = 10000;
		foreach ($this->out as $s) {
			if ($last > $s->sectionTop) $this->pdf->AddPage();
			$last = $s->sectionTop;
			foreach ($s->objects as $c) {
				$O = $this->prop($c->id);
				if (!$O) continue;
				if ($O->objectType === 'field' || $O->objectType === 'label') $this->printField($s, $c, $O);
				if ($O->objectType === 'image') $this->printImage($s, $c, $O);
			}
		}
	}

	public function font(object $O): array
	{
		$family = (string)$O->fontFamily;
		$f = strtolower($family);
		if (!in_array($f, ['helvetica', 'courier', 'times', 'symbol', 'zapfdingbats'], true) && !is_file($this->fontDir . $f . '.php')) $family = 'Helvetica';
		$weight = strtoupper((string)$O->fontWeight);
		$weight = in_array($weight, ['B', 'I', 'BI', 'IB', 'U', 'BU', 'IU'], true) ? $weight : '';
		return [$family, $weight, (float)$O->fontSize > 0 ? (float)$O->fontSize : 10];
	}

	private function printField(object $S, object $c, object $O): void
	{
		[$family, $weight, $size] = $this->font($O);
		$border = (float)$O->borderWidth;
		$back = $c->B ?? $O->backgroundColor;
		$color = $c->F ?? $O->fontColor;
		$dc = nuHex2rgb((string)$O->borderColor);
		$bc = nuHex2rgb((string)$back);
		$tc = nuHex2rgb((string)$color);
		$this->pdf->SetDrawColor($dc[0], $dc[1], $dc[2]);
		$this->pdf->SetTextColor($tc[0], $tc[1], $tc[2]);
		$this->pdf->SetFillColor($bc[0], $bc[1], $bc[2]);
		$this->pdf->SetFont($family, $weight, $size, '', false);
		$this->pdf->SetLineWidth($border / 5);
		$top = $S->sectionTop + $c->top;
		$this->pdf->SetXY((float)$O->left, $top);
		$text = implode("\n", array_map('strval', $c->lines));
		if (str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text) === 'KEEP EXACT HEIGHT') {
			$this->pdf->Rect((float)$O->left, $top, (float)$O->width, (float)$O->height, 'DF');
		} else {
			$align = strtoupper(substr((string)$O->textAlign, 0, 1)) ?: 'L';
			$this->pdf->MultiCell((float)$O->width, (float)$O->height, $text, $border == 0 ? 0 : 1, $align, true, 0, '', '', true, 0, $this->lay->ishtml === 'Y', false);
		}
	}

	private function printImage(object $S, object $c, object $O): void
	{
		if (($c->image ?? '') === '') return;
		try {
			$head = ltrim(substr($c->image, 0, 300));
			if (str_starts_with($head, '<svg') || (str_starts_with($head, '<?xml') && str_contains($head, '<svg'))) {
				$this->pdf->ImageSVG('@' . $c->image, (float)$O->left, $S->sectionTop + $c->top, (float)$O->width, (float)$O->height);
			} else {
				$this->pdf->Image('@' . $c->image, (float)$O->left, $S->sectionTop + $c->top, (float)$O->width, (float)$O->height);
			}
		} catch (Throwable $e) {
			error_log('nuBuilder Next report image ' . $O->id . ': ' . $e->getMessage());
		}
	}

	/** Image bytes: a column (data URL, nuBuilder file JSON or http(s) URL), "Image:<code>" of zzzzsys_file, or an http(s) URL. */
	public function imageData(object $O, array $row): string
	{
		static $cache = [];
		$src = (string)$O->fieldName;
		if ($src === '') return '';
		if (array_key_exists($src, $row)) $src = (string)($row[$src] ?? '');
		elseif (stripos($src, 'Image:') === 0) {
			$code = substr($src, 6);
			if (!isset($cache["file:$code"])) {
				$json = nu_db()->value('SELECT sfi_json FROM zzzzsys_file WHERE sfi_code = ?', [$code]);
				$cache["file:$code"] = $json === null ? '' : NuFile::dataUrl(json_decode((string)$json, true) ?: []);
			}
			$src = $cache["file:$code"];
		}
		if ($src === '') return '';
		if (str_starts_with(ltrim($src), '{')) $src = NuFile::dataUrl(json_decode($src, true) ?: []);
		if (str_starts_with($src, 'data:')) {
			$p = strpos($src, ',');
			if ($p === false) return '';
			$meta = substr($src, 5, $p - 5);
			$body = substr($src, $p + 1);
			return str_contains($meta, ';base64') ? (string)base64_decode($body) : rawurldecode($body);
		}
		if (preg_match('#^https?://#i', $src)) {
			if (isset($cache[$src])) return $cache[$src];
			$ctx = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 1, 'max_redirects' => 3], 'ssl' => ['verify_peer' => true]]);
			$data = @file_get_contents($src, false, $ctx, 0, 5 * 1024 * 1024);
			return $cache[$src] = $data === false ? '' : $data;
		}
		return '';
	}
}

/** nuBuilder's nuSECTION: one section of the report for one row, cut over pages. */
final class NuReportSection
{
	/** @var object[] layout objects with LINES */
	private array $O = [];
	public array $sections = [];
	private float $pageHeight;
	private float $sectionHeight;
	private int $pageBreak;

	public function __construct(private NuReportEngine $engine, private TCPDF $pdf, private array $row, private object $lay, private int $group, private int $section,
		private float $sectionTop, private string $fontDir)
	{
		$S = $lay->groups[$group]->sections[$section];
		foreach ($S->objects as $o) $this->O[] = clone $o;
		$this->pageHeight = (float)$lay->height;
		$this->sectionHeight = (float)$S->height;
		$this->pageBreak = (int)($S->page_break ?? 0);
	}

	public function noPageBreak(): void
	{
		$this->pageBreak = 0;
	}

	public function build(): float
	{
		$this->O = $this->setObjectLines($this->O);
		return $this->chop();
	}

	/** @param object[] $O */
	private function setObjectLines(array $O, bool $stopGrow = false): array
	{
		foreach ($O as $o) {
			$o->image = '';
			if ($o->objectType === 'field' || $o->objectType === 'label') {
				if ($o->objectType === 'field') {
					[, $fmt] = NuReportEngine::stripFormatting((string)($this->row[$o->fieldName] ?? ''));
					if (($fmt['B'] ?? '') !== '') $o->B = '#' . $fmt['B'];
					if (($fmt['F'] ?? '') !== '') $o->F = '#' . $fmt['F'];
				}
				$o->LINES = $this->rows($o, $stopGrow);
			} elseif ($o->objectType === 'image') {
				$o->LINES = [''];
				$o->image = $this->engine->imageData($o, $this->row);
			} else {
				$o->LINES = [];
			}
		}
		return $O;
	}

	/** getObjectRows: the text of a field or label broken into lines that fit the width. */
	private function rows(object $O, bool $stopGrow): array
	{
		$rows = [];
		if ($O->objectType === 'field') {
			$text = $this->engine->fieldValue($O, $this->row, $this->group);
			$lines = explode("\r", str_replace(["\n\r", "\r\n", "\n"], "\r", $text));
		} else {
			$lines = [(string)$O->fieldName];
		}
		[$family, $weight, $size] = $this->engine->font($O);
		$this->pdf->SetFont($family, $weight, $size);
		foreach ($lines as $line) {
			$force = true;
			while (mb_strlen($line) > 0) {
				[$part, $line] = $this->oneRow($line, $O);
				$rows[] = trim($part);
				$force = false;
			}
			if ($force) $rows[] = '';
		}
		if ($stopGrow) return [$rows[0] ?? ''];
		if ($O->minRows > 0) while (count($rows) < $O->minRows) $rows[] = ' ';
		if ($O->maxRows > 0) $rows = array_slice($rows, 0, $O->maxRows);
		if ($O->minRows == -1 && ($rows[0] ?? '') === '') $rows = [];
		return $rows;
	}

	/** getOneRow: [the part that fits, the rest], broken after ", ;-" when possible. */
	private function oneRow(string $text, object $O): array
	{
		$max = (float)$O->width - 2;
		if ($max > $this->pdf->GetStringWidth($text)) return [$text, ''];
		$len = mb_strlen($text);
		$to = 1;
		while ($to < $len && $max > $this->pdf->GetStringWidth(mb_substr($text, 0, $to))) $to++;
		$widest = mb_substr($text, 0, $to);
		$found = false;
		$longest = false;
		$split = $to;
		for ($i = mb_strlen($widest) - 1; $i > 1; $i--) {
			if (!$longest && $this->pdf->GetStringWidth(mb_substr($widest, 0, $i)) < $max) { $split = $i; $longest = true; }
			if (str_contains(', ;-', mb_substr($widest, $i, 1))) { $to = $i; $found = true; break; }
		}
		if (!$found) $to = $split;
		$to = max(1, $to);
		return [mb_substr($text, 0, $to), mb_substr($text, $to)];
	}

	private function chop(): float
	{
		$ids = [];
		$top = $this->sectionTop;
		$parts = [];
		$pages = 0;
		$expanded = $this->sectionHeight + $this->extendedHeight() - .25;
		$pageBreak = $this->pageBreak;
		$isReportHeader = $this->group === 1 && $this->section === 0;

		foreach ($this->O as $o) $ids[] = $o->id;
		foreach ($this->O as $o) {
			$oid = $o->id;
			$parts[$oid] ??= [];
			$available = $this->paperBottom() - $top - $o->top;
			$guard = 0;
			while (count($o->LINES) > 0 && $guard++ < 100000) {
				if (($this->paperBottom() - $top < $this->sectionHeight) || $pageBreak == 1) {   // the section's height won't fit: new page
					if (!$isReportHeader) { $this->pageHeaderFooter(1); $this->pageHeaderFooter(0); }
					$top = $this->paperTop();
					$available = $this->paperBottom() - $this->paperTop();
					$pageBreak = 0;
				}
				$fit = $o->height == 0 ? (int)$available : (int)floor($available / $o->height);
				if ($fit <= 0 && $available >= $this->paperBottom() - $this->paperTop()) $fit = 1;   // taller than a page: one line per page
				$p = new stdClass();
				$p->lines = [];
				$p->id = $oid;
				$p->top = $o->top;
				$p->image = $o->image ?? '';
				if (isset($o->B)) { $p->B = $o->B; unset($o->B); }
				if (isset($o->F)) { $p->F = $o->F; unset($o->F); }
				$p->lines = array_splice($o->LINES, 0, max(0, $fit));
				if ($fit > 0) $parts[$oid][] = $p;
				else $top = $this->paperTop();
				$available = $this->paperBottom() - $this->paperTop();
			}
			$pages = max($pages, count($parts[$oid]));
		}

		for ($i = 0; $i < $pages; $i++) {
			$top = $i === 0 ? $top : $this->paperTop();
			$height = $i + 1 === $pages ? $expanded : $this->paperBottom() - $top;
			$expanded -= $height;
			$s = self::pdfSection($this->group, $this->section, $top, $height);
			$top += $height;
			foreach ($ids as $oid) {
				if (count($parts[$oid] ?? []) <= $pages && $i < count($parts[$oid] ?? [])) $s->objects[] = $parts[$oid][$i];
			}
			$this->sections[] = $s;
			if ($expanded > 0) {
				$s = self::pdfSection($this->group, $this->section, $top, $expanded);
				$top += $expanded;
				$this->sections[] = $s;
			}
			if ($top >= $this->paperBottom() && !$isReportHeader) {
				$this->pageHeaderFooter(1);
				if ($i + 1 !== $pages) $this->pageHeaderFooter(0);
			}
		}
		if ($this->group === 1 && $this->section === 1) $this->pageHeaderFooter(1);   // after the report footer
		return $top;
	}

	private function pageHeaderFooter(int $section): void
	{
		$S = $this->lay->groups[2]->sections[$section];
		$objs = [];
		foreach ($S->objects as $o) $objs[] = clone $o;
		$objs = $this->setObjectLines($objs, true);
		$s = self::pdfSection(2, $section, $section === 0 ? 0 : $this->pageHeight - (float)$S->height, (float)$S->height);
		foreach ($objs as $o) {
			$p = new stdClass();
			$p->id = $o->id;
			$p->top = $o->top;
			$p->lines = $o->LINES;
			$p->image = $o->image ?? '';
			$s->objects[] = $p;
		}
		$this->sections[] = $s;
	}

	private function extendedHeight(): float
	{
		if (!$this->O) return 0;
		$bottom = 0;
		$id = 0;
		foreach ($this->O as $i => $o) {
			$b = $o->top + count($o->LINES) * $o->height;
			if ($bottom < $b) { $id = $i; $bottom = $b; }
		}
		return (count($this->O[$id]->LINES) - 1) * $this->O[$id]->height;
	}

	private function paperTop(): float
	{
		return ($this->group === 1 || $this->group === 2) && $this->section === 0 ? 0 : (float)$this->lay->groups[2]->sections[0]->height + 1;
	}

	private function paperBottom(): float
	{
		if (($this->group === 1 && $this->section === 0) || ($this->group === 2 && $this->section === 1)) return $this->pageHeight;
		return $this->pageHeight - (float)$this->lay->groups[2]->sections[1]->height;
	}

	private static function pdfSection(int $g, int $s, float $t, float $h): object
	{
		$c = new stdClass();
		$c->objects = [];
		$c->group = $g;
		$c->section = $s;
		$c->sectionTop = $t;
		$c->sectionHeight = $h;
		return $c;
	}
}
