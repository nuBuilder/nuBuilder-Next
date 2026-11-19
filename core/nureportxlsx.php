<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Reports as Excel files (0.8.1324): the same data, sort order, groups and totals as the PDF, in two sheets –
 *   "Report": the lines of the report header, a title row (page header labels above the detail fields), per group a header row and,
 *             when the layout has a group footer, a total row (SUM / AVERAGE of the footer), the grand total of the report footer;
 *   "Data":   every column of the data source, one row per record, autofilter.
 * Columns of "Report" = the field objects of the Detail section from left to right; a footer object goes into the column it overlaps.
 * Formats of the layout become Excel number formats (N| → #,##0.00 …, D| → dates), so numbers stay numbers. Pictures, boxes and
 * page header / footer lines are left out. Needs no library (NuXlsx).
 */
final class NuReportXlsx
{
	/** Builds the workbook and returns its bytes. $layoutJson: an unsaved layout (designer). */
	public static function build(array $r, array $hash, ?string $layoutJson = null): string
	{
		require_once NU_ROOT . '/core/form/nuphp_api.php';
		$hash = NuReport::runHash($r, $hash);
		$source = [];
		[$cols, $rows] = NuReport::data((string)$r['sre_zzzzsys_php_id'], $hash, false, $source);
		$lay = NuReport::layout($layoutJson ?? (string)$r['sre_layout']);
		$title = (string)$r['sre_description'] !== '' ? (string)$r['sre_description'] : (string)$r['sre_code'];
		$x = NuXlsx::create($title)->company(nu_app_title())->subject((string)$r['sre_code'])->dateFormats('yyyy-mm-dd', 'yyyy-mm-dd hh:mm');

		// sort as the PDF: the sort fields of the used groups
		$groups = [];
		foreach (NuReport::SORT_GROUPS as $i) if ((string)$lay->groups[$i]->sortField !== '') $groups[] = $i;
		if ($groups) {
			$idx = array_keys($rows);
			usort($idx, static function ($a, $b) use ($rows, $groups, $lay) {
				foreach ($groups as $g) {
					$f = (string)$lay->groups[$g]->sortField;
					$c = self::compare($rows[$a][$f] ?? null, $rows[$b][$f] ?? null);
					if ($c !== 0) return $lay->groups[$g]->sortBy === 'd' ? -$c : $c;
				}
				return $a <=> $b;
			});
			$rows = array_map(static fn($i) => $rows[$i], $idx);
		}

		self::reportSheet($x->sheet(t('rpt_sheet_report')), $lay, $rows, $hash, $groups, $title);
		self::dataSheet($x->sheet(t('rpt_sheet_data')), $source ?: $cols, $rows);
		return $x->toString();
	}

	// ----------------------------------------------------------------- sheet "Report"

	private static function reportSheet(NuXlsxSheet $s, object $lay, array $rows, array $hash, array $groups, string $title): void
	{
		$fields = array_values(array_filter($lay->groups[0]->sections[0]->objects, static fn($o) => $o->objectType === 'field'));
		usort($fields, static fn($a, $b) => [$a->left, $a->top] <=> [$b->left, $b->top]);
		if (!$fields) {                                      // a report without detail fields: the columns of the first row
			foreach (array_keys($rows[0] ?? []) as $c) $fields[] = (object)['fieldName' => (string)$c, 'format' => '', 'left' => 0, 'width' => 0, 'textAlign' => 'left'];
		}
		$n = max(1, count($fields));

		// lines of the report header (labels from top to bottom), else the report's title
		$head = self::labels($lay->groups[1]->sections[0]->objects, $hash);
		foreach ($head ?: [$title] as $i => $line) $s->row([NuXlsx::cell($line, $i === 0 ? ['bold' => true, 'size' => 14] : ['color' => '#555555'])]);
		$s->emptyRow();

		// column titles: a label of the page header (else the report header, the first group header) above the field, else its name
		$titles = [];
		$pool = array_merge($lay->groups[2]->sections[0]->objects, $lay->groups[1]->sections[0]->objects, $groups ? $lay->groups[$groups[0]]->sections[0]->objects : []);
		foreach ($fields as $f) {
			$best = null; $bestOv = 0.0;
			foreach ($pool as $o) {
				if ($o->objectType !== 'label' || self::isBox($o)) continue;
				$ov = self::overlap($f, $o);
				if ($ov > $bestOv) { $best = $o; $bestOv = $ov; }
			}
			$titles[] = $best ? self::labelText($best, $hash) : self::humanise((string)$f->fieldName);
		}
		$headStyle = ['bold' => true, 'bg' => '#EEF2F7', 'border' => 'thin'];
		$s->row(array_map(static fn($t, $f) => NuXlsx::cell($t, $headStyle + ['align' => self::align($f)]), $titles, $fields));
		$s->freeze('A' . ($s->lastRow() + 1));

		// rows with group headers and footers
		$open = [];                                          // level => [value, start index]
		$emitHeader = static function (int $lv, array $row) use ($s, $lay, $groups, $hash, $n) {
			$g = $groups[$lv];
			$objs = $lay->groups[$g]->sections[0]->objects;
			$text = self::lineText($objs, $row, $hash);
			if ($text === '' && !$lay->groups[$g]->sections[1]->objects) return;          // a group only for sorting
			if ($text === '') $text = self::humanise((string)$lay->groups[$g]->sortField) . ': ' . self::plain($row[(string)$lay->groups[$g]->sortField] ?? '');
			$cells = [NuXlsx::cell(str_repeat('   ', $lv) . $text, ['bold' => true, 'bg' => $lv === 0 ? '#DCE6F2' : '#EAF0F7'])];
			for ($i = 1; $i < $n; $i++) $cells[] = NuXlsx::cell(null, ['bg' => $lv === 0 ? '#DCE6F2' : '#EAF0F7']);
			$s->row($cells);
		};
		$emitFooter = static function (array $objs, array $subset, array $row) use ($s, $fields, $n, $hash) {
			$objs = array_values(array_filter($objs, static fn($o) => $o->objectType !== 'image' && !self::isBox($o)));
			if (!$objs) return;
			$cells = array_fill(0, $n, null);
			$labels = [];
			usort($objs, static fn($a, $b) => [$a->left, $a->top] <=> [$b->left, $b->top]);
			foreach ($objs as $o) {
				$col = self::columnOf($o, $fields);
				if ($o->objectType === 'label') { $labels[] = [$col, self::labelText($o, $hash)]; continue; }
				$v = self::value($o, $row, $subset);
				if ($col === null || $cells[$col] !== null) $col = self::freeColumn($cells, $col ?? 0);
				if ($col !== null) $cells[$col] = $v;
			}
			foreach ($labels as [$col, $text]) {
				if ($text === '') continue;
				if ($col === null || $cells[$col] !== null) $col = self::freeColumn($cells, 0);
				if ($col !== null) $cells[$col] = $text;
			}
			$style = ['bold' => true, 'border' => 'thin', 'bg' => '#F6F7F9'];
			$out = [];
			foreach ($cells as $i => $v) $out[] = $v instanceof NuXlsxCell ? NuXlsx::cell($v->value, $v->style + $style) : NuXlsx::cell($v, $style + ['align' => isset($fields[$i]) ? self::align($fields[$i]) : 'left']);
			$s->row($out);
		};

		foreach ($rows as $i => $row) {
			$changed = null;
			foreach ($groups as $lv => $g) {
				$f = (string)$lay->groups[$g]->sortField;
				if (!isset($open[$lv]) || !self::same($open[$lv][0], $row[$f] ?? null)) { $changed = $lv; break; }
			}
			if ($changed !== null) {
				for ($lv = count($groups) - 1; $lv >= $changed; $lv--) {
					if (!isset($open[$lv])) continue;
					$emitFooter($lay->groups[$groups[$lv]]->sections[1]->objects, array_slice($rows, $open[$lv][1], $i - $open[$lv][1]), $rows[$i - 1]);
					unset($open[$lv]);
				}
				for ($lv = $changed, $c = count($groups); $lv < $c; $lv++) {
					$open[$lv] = [$row[(string)$lay->groups[$groups[$lv]]->sortField] ?? null, $i];
					$emitHeader($lv, $row);
				}
			}
			$cells = [];
			foreach ($fields as $f) $cells[] = self::value($f, $row, $rows);
			$s->row($cells);
		}
		$last = count($rows);
		for ($lv = count($groups) - 1; $lv >= 0; $lv--) {
			if (isset($open[$lv])) $emitFooter($lay->groups[$groups[$lv]]->sections[1]->objects, array_slice($rows, $open[$lv][1]), $rows[$last - 1] ?? []);
		}
		if ($rows) $emitFooter($lay->groups[1]->sections[1]->objects, $rows, $rows[$last - 1]);    // report footer = grand total
	}

	// ----------------------------------------------------------------- sheet "Data"

	private static function dataSheet(NuXlsxSheet $s, array $cols, array $rows): void
	{
		$s->header(array_map('strval', $cols));
		foreach ($rows as $row) {
			$line = [];
			foreach ($cols as $c) $line[] = self::typed($row[$c] ?? null, '');
			$s->row($line);
		}
		if ($cols) $s->filter('A1:' . NuXlsx::colName(count($cols)) . max(1, count($rows) + 1));
	}

	// ----------------------------------------------------------------- values

	/** A field's cell: the column (or SUM / AVERAGE over $subset) typed by the layout's format; #B# / #F# colours of the value as style. */
	private static function value(object $o, array $row, array $subset): NuXlsxCell
	{
		$name = (string)$o->fieldName;
		$upper = strtoupper($name);
		$style = ['align' => self::align($o)];
		if (str_starts_with($upper, 'SUM(') || str_starts_with($upper, 'AVERAGE(')) {
			$isAvg = str_starts_with($upper, 'AVERAGE(');
			$args = array_map('trim', explode(',', substr($name, $isAvg ? 8 : 4, -1)));
			$a = $b = 0.0; $seen = false;
			foreach ($subset as $r) {
				$v = self::plain($r[$args[0]] ?? null);
				if ($v !== '') $seen = true;
				$a += (float)$v;
				if ($isAvg) $b += (float)self::plain($r[$args[1] ?? ''] ?? null);
			}
			$v = $isAvg ? ($b == 0 ? 0.0 : $a / $b) : ($seen ? $a : null);
			return NuXlsx::cell($v, $style + self::numStyle((string)$o->format));
		}
		$raw = array_key_exists($name, $row) ? (string)($row[$name] ?? '') : '';
		[$raw, $colour] = NuReportEngine::stripFormatting($raw);
		if (isset($colour['B'])) $style['bg'] = '#' . $colour['B'];
		if (isset($colour['F'])) $style['color'] = '#' . $colour['F'];
		$fmt = (string)$o->format;
		$kind = strlen($fmt) > 2 && $fmt[1] === '|' ? strtoupper($fmt[0]) : '';
		if ($kind === 'T') return NuXlsx::cell(nuAddFormatting($raw, $fmt), $style);
		if ($kind === 'N' && is_numeric($raw)) return NuXlsx::cell((float)$raw, $style + self::numStyle($fmt));
		if ($kind === 'D' && ($d = self::date($raw))) return NuXlsx::cell($d, $style + ['format' => self::dateFmt(substr($fmt, 2))]);
		return NuXlsx::cell(self::typed($raw, ''), $style);
	}

	/** A plain value: whole / decimal numbers (no leading zeros) as numbers, ISO dates as dates, else text. */
	private static function typed(mixed $v, string $fmt): mixed
	{
		if ($v === null) return null;
		if (is_int($v) || is_float($v)) return $v;
		$v = (string)$v;
		if (preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $v)) return str_contains($v, '.') ? (float)$v : (int)$v;
		if ($d = self::date($v)) return $d;
		return $v;
	}

	private static function date(string $v): ?DateTimeImmutable
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v) || str_starts_with($v, '0000')) return null;
		$d = DateTimeImmutable::createFromFormat(strlen($v) === 10 ? '!Y-m-d' : (strlen($v) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s'), $v);
		return $d ?: null;
	}

	/** nuBuilder number format (N|€ 1'000.00 %) → Excel: separators as the reader's locale shows them, prefix / suffix as text. */
	public static function numStyle(string $fmt): array
	{
		if (!str_starts_with(strtoupper($fmt), 'N|')) return [];
		$sp = NuFormat::numberSpec(substr($fmt, 2));
		$body = ($sp['sep'] !== '' ? '#,##0' : '0') . ($sp['places'] > 0 ? '.' . str_repeat('0', $sp['places']) : '');
		$q = static fn(string $t) => $t === '' ? '' : '"' . str_replace('"', '', $t) . '"';
		$one = $q($sp['prefix']) . $body . $q($sp['suffix']);
		$neg = NuFormat::options($fmt)['negative'];
		$minus = $q($sp['prefix']) . '-' . $body . $q($sp['suffix']);
		$f = match ($neg) {
			'brackets' => $one . ';(' . $one . ')',
			'red' => $one . ';[Red]' . $minus,
			'red_brackets' => $one . ';[Red](' . $one . ')',
			default => $one,
		};
		return ['format' => $f];
	}

	/** nuBuilder date pattern (dd.mm.yyyy hh:nn) → Excel (nn = minutes; other characters as text). */
	public static function dateFmt(string $p): string
	{
		$out = '';
		foreach (preg_split('/(yyyy|yy|mmmm|mmm|mm|dddd|ddd|dd|hh|nn|ss)/', $p, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
			if (preg_match('/^(yyyy|yy|mmmm|mmm|mm|dddd|ddd|dd|hh|ss)$/', $t)) $out .= $t;
			elseif ($t === 'nn') $out .= 'mm';
			else $out .= preg_match('/^[ .\/:,-]+$/', $t) ? $t : '"' . str_replace('"', '', $t) . '"';
		}
		return $out !== '' ? $out : 'yyyy-mm-dd';
	}

	// ----------------------------------------------------------------- layout helpers

	/** Labels of a section from top to bottom (boxes and empty labels left out), hash variables filled in. */
	private static function labels(array $objs, array $hash): array
	{
		$ls = array_values(array_filter($objs, static fn($o) => $o->objectType === 'label' && !self::isBox($o)));
		usort($ls, static fn($a, $b) => [$a->top, $a->left] <=> [$b->top, $b->left]);
		return array_values(array_filter(array_map(static fn($o) => self::labelText($o, $hash), $ls), static fn($t) => $t !== ''));
	}

	/** A section as one line: labels and field values from left to right. */
	private static function lineText(array $objs, array $row, array $hash): string
	{
		$objs = array_values(array_filter($objs, static fn($o) => $o->objectType !== 'image' && !self::isBox($o)));
		usort($objs, static fn($a, $b) => [$a->left, $a->top] <=> [$b->left, $b->top]);
		$parts = [];
		foreach ($objs as $o) {
			$t = $o->objectType === 'label' ? self::labelText($o, $hash) : trim(nuAddFormatting(NuReportEngine::stripFormatting((string)($row[(string)$o->fieldName] ?? ''))[0], (string)$o->format));
			if ($t !== '') $parts[] = $t;
		}
		return implode(' · ', $parts);
	}

	private static function labelText(object $o, array $hash): string
	{
		$t = str_ireplace(['#page#', '#pages#'], '', (string)$o->fieldName);
		$t = NuHash::replace($t, $hash, NuHash::RAW);
		$t = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>'], ' ', $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim((string)preg_replace('/\s+/u', ' ', $t));
	}

	private static function isBox(object $o): bool
	{
		return $o->objectType === 'label' && strtoupper(trim((string)$o->fieldName)) === 'KEEP EXACT HEIGHT';
	}

	private static function overlap(object $a, object $b): float
	{
		return max(0.0, min($a->left + $a->width, $b->left + $b->width) - max($a->left, $b->left));
	}

	/** The detail column an object overlaps most (null: none). */
	private static function columnOf(object $o, array $fields): ?int
	{
		$best = null; $bestOv = 0.0;
		foreach ($fields as $i => $f) { $ov = self::overlap($o, $f); if ($ov > $bestOv) { $best = $i; $bestOv = $ov; } }
		if ($best === null && $o->objectType === 'field' && preg_match('/^(SUM|AVERAGE)\(\s*([^,)]+)/i', (string)$o->fieldName, $m)) {
			foreach ($fields as $i => $f) if (strcasecmp((string)$f->fieldName, trim($m[2])) === 0) return $i;
		}
		return $best;
	}

	private static function freeColumn(array $cells, int $from): ?int
	{
		for ($i = $from, $n = count($cells); $i < $n; $i++) if ($cells[$i] === null) return $i;
		for ($i = 0; $i < $from; $i++) if ($cells[$i] === null) return $i;
		return null;
	}

	private static function align(object $o): string
	{
		return in_array((string)$o->textAlign, ['right', 'center'], true) ? (string)$o->textAlign : 'left';
	}

	private static function humanise(string $name): string
	{
		$n = (string)preg_replace('/^[a-z]{2,4}_/', '', $name);
		return ucfirst(str_replace('_', ' ', $n !== '' ? $n : $name));
	}

	private static function plain(mixed $v): string
	{
		return NuReportEngine::stripFormatting((string)($v ?? ''))[0];
	}

	/** As the PDF (NuReportEngine): NULL first, numbers as numbers, text case-insensitive. */
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

	/** Sends the workbook as a download and ends the request. */
	public static function send(string $bytes, string $name): never
	{
		while (ob_get_level() > 0) ob_end_clean();
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Length: ' . strlen($bytes));
		header('Content-Disposition: attachment; filename="' . (preg_replace('/[^\w.\-]+/', '_', $name) ?: 'report') . '.xlsx"');
		header('Cache-Control: private, no-store');
		echo $bytes;
		exit;
	}
}
