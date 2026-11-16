<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Page width (0.8.1226, the owner: A1 · B1 · C1 · D1 · E1). The width of the content of the app pages (main#main with nuContainer,
 * nuFormContainer, container, container-xl – not the full-width tool pages) can be widened:
 *  - per device: the user menu's "Page width" (localStorage nunext-page-width, applied by assets/js/nutheme.js before the first paint)
 *  - default for everyone: Settings → Look (setting row page_width {w}), sent as <html data-nu-pw> by nu_head()
 * Values: standard (as before: 1600 px sheet / 1920 px), wide (WIDE px), full (no limit, no sheet frame) or a number MIN … MAX px.
 */
final class NuPageWidth
{
	public const KEY = 'page_width';
	public const WIDE = 2400;
	public const MIN = 1200;
	public const MAX = 3840;

	/** A stored / posted value as one of standard | wide | full | "<px>" – anything else = standard. */
	public static function clean(string $v): string
	{
		$v = strtolower(trim($v));
		if (in_array($v, ['standard', 'wide', 'full'], true)) return $v;
		if (preg_match('/^\d{3,5}$/', $v)) return (string)max(self::MIN, min(self::MAX, (int)$v));
		return 'standard';
	}

	/** The default of the installation (Settings → Look). */
	public static function default(): string
	{
		try {
			$raw = NuDB::$connected ? nu_setting_value(self::KEY) : null;
		} catch (Throwable) {
			return 'standard';
		}
		$j = $raw !== null ? json_decode($raw, true) : null;
		return self::clean(is_array($j) ? (string)($j['w'] ?? '') : '');
	}

	public static function save(string $w, string $by): string
	{
		$w = self::clean($w);
		NuSchema::ensure();
		nu_setting_save(self::KEY, ['setting_value' => json_encode(['w' => $w, 'by' => $by, 'at' => date('Y-m-d H:i:s')])]);
		return $w;
	}

	/** Attribute of <html> for nutheme.js (only when the default is not standard). */
	public static function htmlAttr(): string
	{
		$d = self::default();
		return $d === 'standard' ? '' : ' data-nu-pw="' . h($d) . '"';
	}

	/** The name of a value for menus and settings. */
	public static function label(string $w): string
	{
		$w = self::clean($w);
		return ctype_digit($w) ? t('pgw_px', (int)$w) : t('pgw_' . $w);
	}
}
