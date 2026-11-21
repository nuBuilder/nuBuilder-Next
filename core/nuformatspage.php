<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nuformats.php (code review R7: a page defines no global function). */
final class NuFormatsPage
{
	/** Sample output for the list. */
	public static function examples(string $key): array
	{
		return match ($key[0]) {
			'D' => [NuFormat::format(date('Y-m-d H:i:s'), $key)],
			'N' => [NuFormat::format('1234567.891', $key), NuFormat::format('-1234.5', $key)],
			default => [],
		};
	}
}
