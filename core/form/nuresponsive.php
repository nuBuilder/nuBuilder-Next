<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Responsive containers of the auto and grid layouts (0.8.959) – stored in an object's sob_all_json "nunext" (nuBuilder Forte ignores it,
 * the pixel positions stay, so Forte keeps showing the form in its absolute layout):
 *   content box  stack {dir: v | h | g, wrap, gap, align, justify, cols: {lg, md, sm}, phone_v}
 *                v = one under the other, h = side by side (wrap: onto the next line when there is no room; phone_v: one under the
 *                other on phones), g = a grid with lg / md / sm columns (desktop ≥ 992 px, tablet 576–991 px, phone < 576 px)
 *   any object   size {w: fixed | fit | fill, px, grow, min, max, span} – the width inside a box with a direction (fixed px, as wide as
 *                its content, or the remaining room shared by grow, between min and max px); span = columns in a grid box
 *                hide [sm, md, lg] – not shown on phones / tablets / desktops (every layout)
 */
final class NuResponsive
{
	public const DIRS    = ['v', 'h', 'g'];
	public const ALIGN   = ['start', 'center', 'end', 'stretch'];
	public const JUSTIFY = ['start', 'center', 'end', 'between'];
	public const WIDTHS  = ['fixed', 'fit', 'fill'];
	public const SCREENS = ['sm', 'md', 'lg'];

	public static function cleanStack(mixed $s): ?array
	{
		if (!is_array($s) || !in_array($s['dir'] ?? '', self::DIRS, true)) return null;
		$out = ['dir' => (string)$s['dir']];
		if (isset($s['gap']) && $s['gap'] !== '' && is_numeric($s['gap'])) $out['gap'] = max(0, min(64, (int)$s['gap']));
		if (in_array($s['align'] ?? '', self::ALIGN, true)) $out['align'] = (string)$s['align'];
		if (in_array($s['justify'] ?? '', self::JUSTIFY, true)) $out['justify'] = (string)$s['justify'];
		if ($out['dir'] === 'h') {
			if (!empty($s['wrap'])) $out['wrap'] = true;
			if (!array_key_exists('phone_v', $s) || !empty($s['phone_v'])) $out['phone_v'] = true;   // default: one under the other on phones
		}
		if ($out['dir'] === 'g') {
			$c = is_array($s['cols'] ?? null) ? $s['cols'] : [];
			$lg = max(1, min(12, (int)($c['lg'] ?? 2) ?: 2));
			$md = max(1, min(12, (int)($c['md'] ?? $lg) ?: $lg));
			$sm = max(1, min(12, (int)($c['sm'] ?? 1) ?: 1));
			$out['cols'] = ['lg' => $lg, 'md' => $md, 'sm' => $sm];
		}
		return $out;
	}

	public static function cleanSize(mixed $s): ?array
	{
		if (!is_array($s)) return null;
		$out = [];
		if (in_array($s['w'] ?? '', self::WIDTHS, true)) $out['w'] = (string)$s['w'];
		foreach (['px' => [10, 3000], 'min' => [0, 3000], 'max' => [0, 3000], 'grow' => [1, 12], 'span' => [1, 12]] as $k => [$lo, $hi]) {
			if (isset($s[$k]) && $s[$k] !== '' && is_numeric($s[$k])) $out[$k] = max($lo, min($hi, (int)$s[$k]));
		}
		if (($out['w'] ?? '') !== 'fixed') unset($out['px']);
		if (($out['w'] ?? '') !== 'fill') unset($out['grow']);
		return $out ?: null;
	}

	public static function cleanHide(mixed $h): ?array
	{
		if (!is_array($h)) return null;
		$out = array_values(array_intersect(self::SCREENS, array_map('strval', $h)));
		return $out && count($out) < 3 ? $out : null;          // hidden everywhere = the object's access (Hidden), not this
	}

	/** Classes of a wrapper hidden on some screens. */
	public static function hideClass(array $o): string
	{
		$h = self::cleanHide($o['nunext']['hide'] ?? null);
		return $h ? ' ' . implode(' ', array_map(static fn($s) => 'nuHide' . ucfirst($s), $h)) : '';
	}

	/** [class, style] of the container of a content box with a direction. */
	public static function box(array $stack): array
	{
		$cls = 'nuStack nuStack-' . $stack['dir'];
		if (!empty($stack['wrap'])) $cls .= ' nuStackWrap';
		if (!empty($stack['phone_v'])) $cls .= ' nuStackPhoneV';
		if (isset($stack['align'])) $cls .= ' nuStackAlign-' . $stack['align'];
		if (isset($stack['justify'])) $cls .= ' nuStackJustify-' . $stack['justify'];
		$style = isset($stack['gap']) ? '--nu-stack-gap:' . (int)$stack['gap'] . 'px;' : '';
		if ($stack['dir'] === 'g') foreach ($stack['cols'] as $k => $n) $style .= '--nu-stack-cols-' . $k . ':' . (int)$n . ';';
		return [$cls, $style];
	}

	/** [class, style] of one object inside a box with a direction. */
	public static function item(array $o, array $stack): array
	{
		$s = self::cleanSize($o['nunext']['size'] ?? null) ?? [];
		$w = $s['w'] ?? ($stack['dir'] === 'h' ? 'fill' : '');
		$cls = 'nuStackItem' . ($w !== '' ? ' nuStackW-' . $w : '');
		$style = '';
		if ($w === 'fixed' && isset($s['px'])) $style .= 'width:' . $s['px'] . 'px;';
		if ($w === 'fill') $style .= '--nu-stack-grow:' . ($s['grow'] ?? 1) . ';';
		if (!empty($s['min'])) $style .= 'min-width:' . $s['min'] . 'px;';
		if (!empty($s['max'])) $style .= 'max-width:' . $s['max'] . 'px;';
		if ($stack['dir'] === 'g' && isset($s['span'])) $style .= '--nu-stack-span:' . $s['span'] . ';';
		return [$cls, $style];
	}

	/**
	 * The nunext keys of an object.save's "grid" part: placement (row, col, span, box – only those sent; null / '' removes) and the
	 * responsive keys (stack, size, hide).
	 */
	public static function gridKeys(array $g, string $type): array
	{
		$set = [];
		if (array_key_exists('row', $g)) $set['row'] = $g['row'] !== '' && $g['row'] !== null ? max(1, (int)$g['row']) : null;
		if (array_key_exists('col', $g)) $set['col'] = $g['col'] !== '' && $g['col'] !== null ? (int)$g['col'] : null;
		if (array_key_exists('span', $g)) $set['span'] = $g['span'] !== '' && $g['span'] !== null ? max(1, min(12, (int)$g['span'])) : null;
		if (array_key_exists('box', $g)) $set['box'] = preg_match('/^\w+$/', (string)$g['box']) ? (string)$g['box'] : null;
		return $set + self::fromRequest($g, $type);
	}

	/** The nunext keys an object.save of design mode may set: stack (content boxes), size, hide – each null = removed. */
	public static function fromRequest(array $g, string $type): array
	{
		$set = [];
		if (array_key_exists('stack', $g)) $set['stack'] = $type === 'contentbox' ? self::cleanStack($g['stack']) : null;
		if (array_key_exists('size', $g)) $set['size'] = self::cleanSize($g['size']);
		if (array_key_exists('hide', $g)) $set['hide'] = self::cleanHide($g['hide']);
		return $set;
	}
}
