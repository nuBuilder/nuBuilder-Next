<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Row details of form lists (0.8.1027): a ▸ at the start of a row (or Space / → on a focused row) folds a strip open below it with
 *  - the columns of the list that are hidden (width 0) or whose text is cut – read from the row itself in the browser, and
 *  - a related list: the rows of one sub-form of the record (up to ROWS), loaded through formapi action "details".
 * Settings in sfo_json nunext.details {cols: true, sub: "<sub-form object id>"} – nuBuilder Forte ignores them; design mode → Browse list.
 * The rows are read with the rights of opening the record: view right on the form (formapi checks it), field data access of the
 * sub-form's objects (withheld / hidden columns left out, masked ones masked), lookups and selects shown by their texts.
 */
final class NuRowDetails
{
	public const ROWS = 10;
	private const TYPES = ['input', 'select', 'textarea', 'lookup'];

	/** Stored setting cleaned: null = off. */
	public static function clean(mixed $d): ?array
	{
		if (!is_array($d)) return null;
		$out = [];
		if (!empty($d['cols']) && $d['cols'] !== '0') $out['cols'] = true;
		$sub = is_string($d['sub'] ?? null) ? trim($d['sub']) : '';
		if ($sub !== '' && preg_match('~^[\w.-]{1,100}$~', $sub)) $out['sub'] = $sub;
		return $out ?: null;
	}

	/** The design API's form.save field "details" → the key of sfo_json nunext (null removes it). */
	public static function keysIn(array $fields): array
	{
		return array_key_exists('details', $fields) ? ['details' => self::clean($fields['details'])] : [];
	}

	/** Setting of a form; the related list only while its sub-form object still exists. */
	public static function of(NuFormMeta $meta): ?array
	{
		$d = self::clean(NuFormMeta::nunextJson((string)($meta->form['sfo_json'] ?? ''))['details'] ?? null);
		if ($d && isset($d['sub']) && !self::subObject($meta, $d['sub'])) unset($d['sub']);
		return $d ?: null;
	}

	/** The sub-form object (type subform, table style or form style) of the form by its id (sob_all_id). */
	public static function subObject(NuFormMeta $meta, string $id): ?array
	{
		foreach ($meta->objects as $o) if ($o['sob_all_type'] === 'subform' && (string)$o['sob_all_id'] === $id) return $o;
		return null;
	}

	/** Client data of the list: {cols, sub (label of the related list)} or null. */
	public static function client(NuFormMeta $meta): ?array
	{
		$d = self::of($meta);
		if (!$d) return null;
		$o = isset($d['sub']) ? self::subObject($meta, $d['sub']) : null;
		return ['cols' => !empty($d['cols']), 'sub' => $o ? (trim(strip_tags((string)$o['sob_all_label'])) ?: (string)$o['sob_all_id']) : ''];
	}

	/** The columns of the related list: the sub-form's fields as its table shows them, without withheld / hidden ones. */
	public static function columns(NuFormMeta $sub): array
	{
		$cols = array_values(array_filter($sub->objects, static fn($o) => in_array($o['sob_all_type'], self::TYPES, true)
			&& !in_array((string)$o['sob_input_type'], ['button', 'file', 'password'], true) && !NuFieldAccess::withheld($o) && !NuRecord::hiddenFor($o)));
		usort($cols, static fn($a, $b) => [(int)$a['sob_all_order'], (int)$a['sob_all_left']] <=> [(int)$b['sob_all_order'], (int)$b['sob_all_left']]);
		return $cols;
	}

	/**
	 * The related list of one record: {title, cols: [labels], rows: [[cells]], total, more}. Empty rows when the setting has no
	 * related list or the sub-form is missing.
	 */
	public static function related(NuFormMeta $meta, string $recordId): array
	{
		$d = self::of($meta);
		$o = $d && isset($d['sub']) ? self::subObject($meta, $d['sub']) : null;
		$sub = $o ? NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']) : null;
		if (!$o || !$sub || $recordId === '' || $recordId === '-1') return ['title' => '', 'cols' => [], 'rows' => [], 'total' => 0, 'more' => false];
		$hash = NuHash::forForm($meta, $recordId);
		$rows = NuRecord::subformRows($o, $recordId);
		$cols = self::columns($sub);
		$render = new NuRender($sub, '-1', [], $hash, 'auto');
		$out = [];
		foreach (array_slice($rows, 0, self::ROWS) as $r) {
			$cells = [];
			foreach ($cols as $c) $cells[] = self::cell($c, $r, $render, $hash);
			$out[] = $cells;
		}
		return [
			'title' => trim(strip_tags((string)$o['sob_all_label'])) ?: (string)$o['sob_all_id'],
			'cols' => array_map(static fn($c) => trim(strip_tags((string)$c['sob_all_label'])) ?: (string)$c['sob_all_id'], $cols),
			'align' => array_map(static fn($c) => self::isNumber($c) ? 'r' : 'l', $cols),
			'rows' => $out, 'total' => count($rows), 'more' => count($rows) > self::ROWS,
		];
	}

	private static function isNumber(array $o): bool
	{
		return $o['sob_all_type'] === 'input' && in_array((string)$o['sob_input_type'], ['nuNumber', 'number'], true);
	}

	/** One value as text: formats of the field, the text of a select option, the description of a lookup, ✓ for a ticked box. */
	private static function cell(array $o, array $row, NuRender $render, array $hash): string
	{
		$field = (string)$o['sob_all_id'];
		$v = null;
		if (array_key_exists($field, $row)) $v = $row[$field];
		else foreach ($row as $k => $x) if (strcasecmp((string)$k, $field) === 0) { $v = $x; break; }
		$v = (string)(NuSecurity::demoMask($field, $v) ?? '');
		if (NuFieldAccess::masked($o)) return NuFieldAccess::maskValue($v, NuFieldAccess::maskOf($o));
		if ($v === '') return '';
		switch ($o['sob_all_type']) {
			case 'lookup':
				try { $l = NuLookup::byId($o, $v, $hash); } catch (Throwable $e) { $l = null; }
				return $l ? (string)($l['description'] !== '' ? $l['description'] : $l['code']) : '';
			case 'select':
				$vals = str_starts_with($v, '[') && is_array($j = json_decode($v, true)) ? array_map('strval', $j) : [$v];
				$texts = [];
				foreach ($render->options($o) as $op) if (in_array((string)$op[0], $vals, true)) $texts[] = (string)$op[1];
				return $texts ? implode(', ', $texts) : $v;
			case 'textarea':
				$t = trim(preg_replace('~\s+~u', ' ', strip_tags($v)) ?? '');
				return mb_strlen($t) > 200 ? mb_substr($t, 0, 199) . '…' : $t;
		}
		$type = (string)$o['sob_input_type'];
		if ($type === 'checkbox') return $v === '1' ? '✓' : '';
		$fmt = trim((string)$o['sob_input_format']);
		if (in_array($type, ['nuDate', 'nuNumber'], true) && $fmt !== '') return NuFormat::format($v, $fmt);
		if (in_array($type, ['text', 'tel', 'search', ''], true) && str_starts_with($fmt, 'T|')) return NuFormat::format($v, $fmt);
		return mb_strlen($v) > 200 ? mb_substr($v, 0, 199) . '…' : $v;
	}
}
