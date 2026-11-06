<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder Forte's nuOnProcessObjects() (Before Edit) and nuOnProcessBrowseRows() (Before Browse) (0.8.958).
 *
 * A form's Before Edit / Before Browse code may declare one of these functions. Forte does not call it: it takes the function's
 * body out of the code (nuExtractFunctionBody) and runs that body with $f = the form object, so the code changes
 * $f->objects (label, value, position, access …) or $f->browse_rows ([0] = primary key, [1…] = the list's columns) before the
 * form or list is drawn. nuBuilder Next does the same with its own objects / rows and takes the changes back.
 */
final class NuOnProcess
{
	/** Object properties a nuOnProcessObjects() body may change → the zzzzsys_object column they stand for. */
	private const PROPS = ['label' => 'sob_all_label', 'top' => 'sob_all_top', 'left' => 'sob_all_left', 'width' => 'sob_all_width',
		'height' => 'sob_all_height', 'valid' => 'sob_all_validate', 'read' => 'sob_all_access', 'align' => 'sob_all_align'];

	/** The function's body (with its braces) as Forte's nuExtractFunctionBody finds it, or null. */
	public static function body(?string $code, string $function): ?string
	{
		if ($code === null || !str_contains($code, $function)) return null;
		$fn = preg_quote($function, '/');
		if (preg_match('/function\s+' . $fn . '\s*\((?<param>[^\)]*)\)\s*(?<body>\{(?:[^{}]+|(?&body))*\})/', $code, $m)
			|| preg_match('/\$' . $fn . '\s+=\s+function\s*\((?<param>[^\)]*)\)\s*(?<body>\{(?:[^{}]+|(?&body))*\})/', $code, $m)) return $m['body'];
		return null;
	}

	/**
	 * nuOnProcessObjects() of the Before Edit code: changes the main form's objects of $meta and the values in $row.
	 * @return bool false when the body failed (the error is in NuPhp::$errors)
	 */
	public static function objects(NuFormMeta $meta, ?string $code, array &$row, array $hash): bool
	{
		$body = self::body($code, 'nuOnProcessObjects');
		if ($body === null) return true;
		$f = new stdClass();
		$f->id = $meta->id;
		$f->record_id = (string)($hash['RECORD_ID'] ?? '');
		$f->objects = [];
		$map = [];
		foreach ($meta->objects as $i => $o) {
			$field = (string)$o['sob_all_id'];
			$x = new stdClass();
			$x->id = $field;
			$x->object_id = (string)$o['zzzzsys_object_id'];
			$x->type = (string)$o['sob_all_type'];
			$x->input = (string)($o['sob_input_type'] ?? '');
			$x->format = (string)($o['sob_input_format'] ?? '');
			foreach (self::PROPS as $p => $c) $x->$p = $o[$c] ?? null;
			$x->value = self::rowValue($row, $field);
			$f->objects[] = $x;
			$map[] = [$i, $x->value];
		}
		$ok = NuPhp::run($body, $hash, 'nuOnProcessObjects', ['f' => $f]);
		foreach ($f->objects as $n => $x) {
			if (!($x instanceof stdClass) || !isset($map[$n])) continue;
			[$i, $before] = $map[$n];
			foreach (self::PROPS as $p => $c) {
				if (property_exists($x, $p) && (string)($x->$p ?? '') !== (string)($meta->objects[$i][$c] ?? '')) $meta->objects[$i][$c] = $x->$p === null ? null : (string)$x->$p;
			}
			if (property_exists($x, 'value') && (string)($x->value ?? '') !== (string)$before) $row[(string)$meta->objects[$i]['sob_all_id']] = $x->value === null ? null : (string)$x->value;
		}
		return $ok;
	}

	/** A callable for NuBrowse::query: runs nuOnProcessBrowseRows() of the Before Browse code on the page's rows, or null. */
	public static function rowsHook(?string $code, array $hash): ?callable
	{
		$body = self::body($code, 'nuOnProcessBrowseRows');
		if ($body === null) return null;
		return static function (array $rows) use ($body, $hash): array {
			$f = new stdClass();
			$f->browse_rows = $rows;
			NuPhp::run($body, $hash, 'nuOnProcessBrowseRows', ['f' => $f]);
			return is_array($f->browse_rows) ? array_values($f->browse_rows) : $rows;
		};
	}

	private static function rowValue(array $row, string $field): ?string
	{
		if (array_key_exists($field, $row)) return $row[$field] === null ? null : (string)$row[$field];
		foreach ($row as $k => $v) if (strcasecmp((string)$k, $field) === 0) return $v === null ? null : (string)$v;
		return null;
	}
}
