<?php
defined('NU_PAGE') || exit;

/**
 * Pizzas drawn as SVG from their toppings – no pictures to upload, and a new pizza gets its own look at once.
 * The same toppings always give the same picture (the positions come from a seed made of the name).
 */
final class PizzaSvg
{
	public static function draw(string $name, array $toppings, int $size = 160): string
	{
		$seed = crc32($name);
		$rnd = function () use (&$seed): float { $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $seed / 0x7fffffff; };
		$o = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="' . $size . '" height="' . $size . '" role="img" aria-label="'
			. htmlspecialchars($name, ENT_QUOTES) . '">';
		$o .= '<circle cx="100" cy="100" r="96" fill="#d89a52"/><circle cx="100" cy="100" r="88" fill="#e9b872"/>';
		$sauce = in_array('tomato', $toppings, true) || !$toppings;
		$o .= '<circle cx="100" cy="100" r="80" fill="' . ($sauce ? '#c8432b' : '#f3e3b5') . '"/>';
		if (in_array('mozzarella', $toppings, true)) {
			// melted cheese: a soft layer with a few brighter spots
			$o .= '<circle cx="100" cy="100" r="74" fill="#f3d58a" opacity=".55"/>';
			for ($i = 0; $i < 7; $i++) {
				[$x, $y] = self::spot($rnd, 58);
				$o .= sprintf('<ellipse cx="%.1f" cy="%.1f" rx="%.1f" ry="%.1f" fill="#fbeec8" opacity=".85" transform="rotate(%d %.1f %.1f)"/>',
					$x, $y, 9 + $rnd() * 6, 6 + $rnd() * 4, (int)($rnd() * 180), $x, $y);
			}
		}
		foreach ($toppings as $t) {
			$n = ['salami' => 7, 'ham' => 6, 'mushroom' => 7, 'olive' => 8, 'basil' => 5, 'pepper' => 7, 'onion' => 6, 'pineapple' => 7,
				'chili' => 6, 'rucola' => 9, 'corn' => 14][$t] ?? 0;
			for ($i = 0; $i < $n; $i++) {
				[$x, $y] = self::spot($rnd, 66);
				$a = (int)($rnd() * 360);
				$o .= match ($t) {
					'salami' => sprintf('<circle cx="%.1f" cy="%.1f" r="10" fill="#9e2a2b"/><circle cx="%.1f" cy="%.1f" r="2" fill="#e8b4a0"/>', $x, $y, $x + 3, $y - 2),
					'ham' => sprintf('<rect x="%.1f" y="%.1f" width="15" height="11" rx="3" fill="#f2a7a7" transform="rotate(%d %.1f %.1f)"/>', $x - 7, $y - 5, $a, $x, $y),
					'mushroom' => sprintf('<g transform="rotate(%d %.1f %.1f)"><path d="M%.1f %.1f a9 7 0 0 1 18 0 z" fill="#c9b49a"/><rect x="%.1f" y="%.1f" width="5" height="7" fill="#e6d8c3"/></g>',
						$a, $x, $y, $x - 9, $y, $x - 2.5, $y),
					'olive' => sprintf('<circle cx="%.1f" cy="%.1f" r="5" fill="none" stroke="#2b2b2b" stroke-width="3"/>', $x, $y),
					'basil' => sprintf('<ellipse cx="%.1f" cy="%.1f" rx="9" ry="5" fill="#3f8f3a" transform="rotate(%d %.1f %.1f)"/>', $x, $y, $a, $x, $y),
					'pepper' => sprintf('<path d="M%.1f %.1f q8 -8 16 0" fill="none" stroke="%s" stroke-width="4" stroke-linecap="round" transform="rotate(%d %.1f %.1f)"/>',
						$x - 8, $y, $i % 2 ? '#3b9c3b' : '#e2b41b', $a, $x, $y),
					'onion' => sprintf('<path d="M%.1f %.1f a8 8 0 0 1 16 0" fill="none" stroke="#b58ac9" stroke-width="2.5" transform="rotate(%d %.1f %.1f)"/>', $x - 8, $y, $a, $x, $y),
					'pineapple' => sprintf('<path d="M%.1f %.1f l12 -4 l-4 12 z" fill="#f4d03f" transform="rotate(%d %.1f %.1f)"/>', $x - 4, $y + 2, $a, $x, $y),
					'chili' => sprintf('<path d="M%.1f %.1f q6 -10 12 -2" fill="none" stroke="#e11d1d" stroke-width="3.5" stroke-linecap="round" transform="rotate(%d %.1f %.1f)"/>',
						$x - 6, $y, $a, $x, $y),
					'rucola' => sprintf('<path d="M%.1f %.1f q5 -9 12 -3 q-6 2 -12 3" fill="#4c9a2a" transform="rotate(%d %.1f %.1f)"/>', $x - 6, $y, $a, $x, $y),
					'corn' => sprintf('<circle cx="%.1f" cy="%.1f" r="3.2" fill="#f7c948"/>', $x, $y),
					default => '',
				};
			}
		}
		return $o . '</svg>';
	}

	/** A random point inside a circle of radius $r around the middle. */
	private static function spot(callable $rnd, float $r): array
	{
		$a = $rnd() * M_PI * 2;
		$d = sqrt($rnd()) * $r;
		return [100 + cos($a) * $d, 100 + sin($a) * $d];
	}
}
