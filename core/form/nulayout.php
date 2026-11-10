<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Turns pixel-positioned nuBuilder objects into responsive rows.
 *
 *  auto: rows derived from sob_all_top (objects within ROW_TOLERANCE px share a row), ordered by
 *        sob_all_left, Bootstrap column span proportional to the object's width. Objects whose
 *        position lies inside a contentbox are placed in that box.
 *  grid: explicit placement from sob_all_json {"nunext":{"row":3,"span":6,"box":"contentbox_id"}};
 *        objects without a row fall back to the auto placement after the explicit rows.
 *
 * Result: ['rows' => [[item, …], …]] where item = ['obj' => object, 'span' => 1–12, 'children' => tree|null]
 */
final class NuLayout
{
	private const ROW_TOLERANCE = 14;

	public static function build(array $objects, string $mode): array
	{
		$boxes = array_values(array_filter($objects, static fn($o) => $o['sob_all_type'] === 'contentbox'));
		$items = array_values(array_filter($objects, static fn($o) => $o['sob_all_type'] !== 'contentbox'));

		// container of every object / box: the smallest box containing its centre (or the box named in nunext.box)
		$parent = [];
		foreach (array_merge($items, $boxes) as $o) {
			$parent[$o['zzzzsys_object_id']] = self::containerOf($o, $boxes, $mode);
		}

		$width = 0;
		foreach ($objects as $o) $width = max($width, (int)($o['sob_all_left'] ?? 0) + self::effectiveWidth($o));
		return self::tree('', $objects, $parent, max($width, 600), $mode, 0);
	}

	private static function containerOf(array $o, array $boxes, string $mode): string
	{
		if ($mode === 'grid' && !empty($o['nunext']['box'])) {
			foreach ($boxes as $b) if ($b['sob_all_id'] === $o['nunext']['box'] && $b !== $o) return $b['zzzzsys_object_id'];
		}
		$cx = (int)($o['sob_all_left'] ?? 0) + max(1, (int)($o['sob_all_width'] ?? 0)) / 2;
		$cy = (int)($o['sob_all_top'] ?? 0) + max(1, (int)($o['sob_all_height'] ?? 0)) / 2;
		$best = '';
		$bestArea = PHP_INT_MAX;
		foreach ($boxes as $b) {
			if ($b['zzzzsys_object_id'] === $o['zzzzsys_object_id']) continue;
			[$l, $t, $w, $h] = [(int)$b['sob_all_left'], (int)$b['sob_all_top'], (int)$b['sob_all_width'], (int)$b['sob_all_height']];
			$area = $w * $h;
			if ($cx >= $l && $cx <= $l + $w && $cy >= $t && $cy <= $t + $h && $area < $bestArea
				&& ($o['sob_all_type'] !== 'contentbox' || $area > (int)($o['sob_all_width'] ?? 0) * (int)($o['sob_all_height'] ?? 0))) {
				$best = $b['zzzzsys_object_id'];
				$bestArea = $area;
			}
		}
		return $best;
	}

	private static function tree(string $container, array $all, array $parent, int $containerWidth, string $mode, int $depth): array
	{
		$children = array_values(array_filter($all, static fn($o) => ($parent[$o['zzzzsys_object_id']] ?? '') === $container));
		if ($depth > 5) return ['rows' => []];

		$explicit = [];
		$implicit = [];
		foreach ($children as $o) {
			if ($mode === 'grid' && isset($o['nunext']['row']) && (int)$o['nunext']['row'] > 0) $explicit[] = $o;
			else $implicit[] = $o;
		}

		$rows = [];
		// explicit grid rows
		usort($explicit, static fn($a, $b) => [(int)$a['nunext']['row'], (int)($a['nunext']['col'] ?? 0), (int)$a['sob_all_left']]
			<=> [(int)$b['nunext']['row'], (int)($b['nunext']['col'] ?? 0), (int)$b['sob_all_left']]);
		foreach ($explicit as $o) $rows[(int)$o['nunext']['row']][] = $o;
		ksort($rows);
		$rows = array_values($rows);
		$explicitRows = count($rows);                         // these keep the column order set in design mode

		// automatic rows by top position
		usort($implicit, static fn($a, $b) => [(int)$a['sob_all_top'], (int)$a['sob_all_left']] <=> [(int)$b['sob_all_top'], (int)$b['sob_all_left']]);
		$current = [];
		$rowTop  = null;
		foreach ($implicit as $o) {
			$top = (int)($o['sob_all_top'] ?? 0);
			if ($rowTop !== null && $top - $rowTop > self::ROW_TOLERANCE) {
				$rows[] = $current;
				$current = [];
			}
			if (!$current) $rowTop = $top;
			$current[] = $o;
		}
		if ($current) $rows[] = $current;

		$out = [];
		foreach ($rows as $r => $row) {
			// grid rows are already ordered by column (then left); only automatic rows go by the left position
			if ($r >= $explicitRows) usort($row, static fn($a, $b) => (int)$a['sob_all_left'] <=> (int)$b['sob_all_left']);
			$fixed = [];
			$spans = [];
			foreach ($row as $i => $o) {
				$own = $mode === 'grid' && isset($o['nunext']['span']) && (int)$o['nunext']['span'] > 0;
				$fixed[$i] = $own;
				$spans[$i] = max(1, min(12, $own ? (int)$o['nunext']['span'] : self::span($o, $containerWidth)));
			}
			$spans = self::fitRow($spans, $fixed);
			$items = [];
			foreach ($row as $i => $o) {
				$items[] = [
					'obj'      => $o,
					'span'     => $spans[$i],
					'children' => $o['sob_all_type'] === 'contentbox'
						? self::tree($o['zzzzsys_object_id'], $all, $parent, max(200, (int)($o['sob_all_width'] ?? 0)), $mode, $depth + 1)
						: null,
				];
			}
			$out[] = $items;
		}
		return ['rows' => $out];
	}

	/**
	 * Spans worked out from widths are rounded one by one, so a row of them can add up to more than 12 and Bootstrap
	 * would push the last field into a new line. Such spans are scaled down to the room left next to the spans set by
	 * hand (largest remainder, at least 1 each) – spans set in design mode are never changed.
	 *
	 * @param array<int, int>  $spans
	 * @param array<int, bool> $fixed  true = span set by hand
	 * @return array<int, int>
	 */
	public static function fitRow(array $spans, array $fixed): array
	{
		$auto = array_keys(array_filter($fixed, static fn($f) => !$f));
		if (!$auto || array_sum($spans) <= 12) return $spans;
		$room = 12;
		foreach ($spans as $i => $s) if (!empty($fixed[$i])) $room -= $s;
		if ($room < count($auto)) {                           // no room: one column each, the row wraps
			foreach ($auto as $i) $spans[$i] = 1;
			return $spans;
		}
		$sum = 0;
		foreach ($auto as $i) $sum += $spans[$i];
		$rest = [];
		$used = 0;
		foreach ($auto as $i) {
			$exact = $spans[$i] * $room / $sum;
			$spans[$i] = max(1, (int)floor($exact));
			$rest[$i] = $exact - floor($exact);
			$used += $spans[$i];
		}
		arsort($rest);                                        // stable in PHP 8: equal remainders keep the row order
		foreach (array_keys($rest) as $i) {
			if ($used >= $room) break;
			$spans[$i]++;
			$used++;
		}
		// the minimum of 1 can go over the room – take it back from the widest automatic spans
		while ($used > $room) {
			$w = null;
			foreach ($auto as $i) if ($spans[$i] > 1 && ($w === null || $spans[$i] > $spans[$w])) $w = $i;
			if ($w === null) break;
			$spans[$w]--;
			$used--;
		}
		return $spans;
	}

	public static function effectiveWidth(array $o): int
	{
		$w = (int)($o['sob_all_width'] ?? 0);
		if ($o['sob_all_type'] === 'lookup') $w += NuRender::lookupDescWidth($o) + 30;
		return max($w, 1);
	}

	private static function span(array $o, int $containerWidth): int
	{
		$type = $o['sob_all_type'];
		if (in_array($type, ['subform', 'run', 'html', 'chart'], true) && (int)($o['sob_all_width'] ?? 0) > $containerWidth * 0.75) return 12;
		$w = self::effectiveWidth($o);
		if (in_array($type, ['input', 'select', 'lookup', 'calc', 'display'], true) && !in_array($o['sob_input_type'], ['button', 'checkbox'], true)) {
			$w = max($w, 140);                                // room for the label above the field
		}
		return (int)max(2, min(12, round($w / $containerWidth * 12)));
	}

	/** Flattened placement (row numbers, spans, boxes) – used by the builder to convert a form to grid mode. */
	public static function placement(array $objects): array
	{
		$tree = self::build($objects, 'auto');
		$out  = [];
		$walk = static function (array $t, string $box) use (&$walk, &$out) {
			foreach ($t['rows'] as $r => $items) {
				foreach ($items as $c => $it) {
					$out[$it['obj']['zzzzsys_object_id']] = ['row' => ($r + 1) * 10, 'col' => ($c + 1) * 10, 'span' => $it['span'], 'box' => $box];
					if ($it['children']) $walk($it['children'], (string)$it['obj']['sob_all_id']);
				}
			}
		};
		$walk($tree, '');
		return $out;
	}
}
