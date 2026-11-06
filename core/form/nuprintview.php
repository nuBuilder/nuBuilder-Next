<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder Forte's print settings of a browse list (0.8.958) – set by form code with nuPrintIncludeColumns(), nuPrintExcludeColumns(),
 * nuPrintIncludeHiddenColumns(), nuPrintUseBrowseFormats() and nuPrintCSVExportOptions() (JavaScript, they set properties of the same
 * names, as Forte's nuform.js) and read here for the print view and its CSV / Excel export.
 */
final class NuPrintView
{
	/** Column numbers of a property: Forte's nuEncode("0,2") (base64), a JSON list or a plain "0,2". @return list<int> */
	public static function numbers(mixed $v): array
	{
		if (is_array($v)) $list = $v;
		else {
			$s = trim((string)$v);
			if ($s === '') return [];
			$d = preg_match('/^[A-Za-z0-9+\/]+=*$/', $s) && !ctype_digit($s) ? base64_decode($s, true) : false;
			if (is_string($d) && $d !== '') $s = $d;
			$j = json_decode($s, true);
			$list = is_array($j) ? $j : explode(',', $s);
		}
		$out = [];
		foreach ($list as $x) if (is_numeric(trim((string)$x))) $out[] = (int)$x;
		return array_values(array_unique($out));
	}

	/** True when form code switched the print view's settings on (one of the properties is set). */
	public static function used(array $hash): bool
	{
		foreach (['nuPrintIncludedColumns', 'nuPrintExcludedColumns', 'nuPrintIncludeHiddenColumns'] as $k) if ((string)($hash[$k] ?? '') !== '') return true;
		return false;
	}

	/**
	 * The columns to print, keys kept: included columns only (by number, also hidden ones), else all shown ones minus the excluded;
	 * hidden columns (width 0) with nuPrintIncludeHiddenColumns(true) – as Forte's nuRunHTMLPrintColumn.
	 */
	public static function columns(array $cols, array $hash): array
	{
		$inc = self::numbers($hash['nuPrintIncludedColumns'] ?? '');
		$exc = self::numbers($hash['nuPrintExcludedColumns'] ?? '');
		$hidden = (string)($hash['nuPrintIncludeHiddenColumns'] ?? '') === '1';
		$out = [];
		foreach ($cols as $k => $c) {
			$i = (int)$c['index'];
			$show = $inc ? in_array($i, $inc, true) : (!in_array($i, $exc, true) && ((int)$c['width'] > 0 || $hidden));
			if (!$show) continue;
			if ((int)$c['width'] <= 0) $c['width'] = 100;
			$out[$k] = $c;
		}
		return $out;
	}

	/** nuPrintUseBrowseFormats(false): values as stored. Not set: formatted as in the list (nuBuilder Next's default). */
	public static function raw(array $hash): bool
	{
		return (string)($hash['nuPrintUseBrowseFormats'] ?? '') === '0';
	}

	/** nuPrintCSVExportOptions({…}) → the options (delimiter, lineTerminator, includeHeaders, bom, fileName, autoDownload, autoClose). */
	public static function csvOptions(array $hash): array
	{
		$v = $hash['nuPrintCSVExportOptions'] ?? '';
		if (is_array($v)) return $v;
		$s = trim((string)$v);
		if ($s === '') return [];
		$d = base64_decode($s, true);
		$j = json_decode(is_string($d) && $d !== '' ? $d : $s, true);
		if (!is_array($j)) return [];
		$keep = [];
		foreach (['delimiter', 'lineTerminator', 'fileName'] as $k) if (isset($j[$k]) && is_scalar($j[$k])) $keep[$k] = (string)$j[$k];
		foreach (['includeHeaders', 'bom', 'autoDownload', 'autoClose'] as $k) if (isset($j[$k])) $keep[$k] = (bool)$j[$k];
		return $keep;
	}
}
