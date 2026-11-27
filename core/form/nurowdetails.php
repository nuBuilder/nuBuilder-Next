<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Row details of form lists (extended): a ▸ at the start of a row (or Space / → on a focused row) folds a strip open below it with
 *  - the columns of the list that are hidden (width 0) or whose text is cut – read from the row itself in the browser,
 *  - related lists as tabs: sub-forms of the record and child tables ("t:<table>.<column>" = rows of <table> whose <column>
 *    holds the record's id), each up to ROWS rows, loaded through formapi action "details" {list},
 *  - a detail form: any form shown read only in a frame with the row's record (?p=form&f=&r=&iframe=1&rdv=1 – its rights,
 *    field access and code apply as when it is opened).
 * Settings in sfo_json nunext.details {cols, own, subs: [ids], form, mode one|many} – the key sub of is read as the first list;
 * nuBuilder Forte ignores them; design mode → Browse list, the list's side pane → Columns.
 * The rows are read with the rights of opening the record: view right on the form (formapi checks it), field data access of the
 * sub-form's objects (withheld / hidden columns left out, masked ones masked), lookups and selects shown by their texts. A child
 * table is read as it is (the designer chose it): never a table of nuBuilder (zzzzsys_), at most COLS columns.
 * Hooks in the browser: nuOnRowExpand(pk, element) / nuOnRowCollapse(pk), nuNextRowDetails(fn) fills the strip with own content.
 */
final class NuRowDetails
{
	/** Session key of candidates(): child-table columns found in the catalogue, per table (ten minutes). */
	public const FOUND = NuSess::ROW_DETAILS_FOUND;

	public const ROWS = 10;
	private const TYPES = ['input', 'select', 'textarea', 'lookup'];

	public const COLS = 6;
	public const LISTS = 6;

	/** Stored setting cleaned: null = off. */
	public static function clean(mixed $d): ?array
	{
		if (!is_array($d)) return null;
		$out = [];
		if (!empty($d['cols']) && $d['cols'] !== '0') $out['cols'] = true;
		if (!empty($d['own']) && $d['own'] !== '0') $out['own'] = true;   // content from the form's code (nuNextRowDetails)
		$subs = [];
		foreach (array_merge([$d['sub'] ?? null], is_array($d['subs'] ?? null) ? $d['subs'] : []) as $x) {
			$x = is_string($x) ? trim($x) : '';
			if ($x !== '' && self::validKey($x) && !in_array($x, $subs, true) && count($subs) < self::LISTS) $subs[] = $x;
		}
		if ($subs) $out['subs'] = $subs;
		$f = is_string($d['form'] ?? null) ? trim($d['form']) : '';
		if ($f !== '' && preg_match('~^[\w.-]{1,100}$~', $f)) $out['form'] = $f;
		if ($out && ($d['mode'] ?? '') === 'one') $out['mode'] = 'one';
		return $out ? $out : null;
	}

	/** A list key: a sub-form object id, or t:<table>.<column> (a child table). */
	public static function validKey(string $k): bool
	{
		return (bool)preg_match('~^(?:[\w.-]{1,100}|t:[A-Za-z_][\w$]{0,63}\.[A-Za-z_][\w$]{0,63})$~', $k);
	}

	/** The design API's form.save field "details" → the key of sfo_json nunext (null removes it). */
	public static function keysIn(array $fields): array
	{
		return array_key_exists('details', $fields) ? ['details' => self::clean($fields['details'])] : [];
	}

	/** Setting of a form; lists only while their sub-form object / child table still exists, the form only while it exists. */
	public static function of(NuFormMeta $meta): ?array
	{
		$d = self::clean(NuFormMeta::nunextJson((string)($meta->form['sfo_json'] ?? ''))['details'] ?? null);
		if (!$d) return null;
		if (isset($d['subs'])) {
			$d['subs'] = array_values(array_filter($d['subs'], static fn($k) => self::listSource($meta, $k) !== null));
			if (!$d['subs']) unset($d['subs']);
		}
		if (isset($d['form']) && (($f = NuFormMeta::load($d['form'])) === null || !$f->hasEdit())) unset($d['form']);
		return ($d['cols'] ?? false) || ($d['own'] ?? false) || isset($d['subs']) || isset($d['form']) ? $d : null;
	}

	/** The sub-form object (type subform, table style or form style) of the form by its id (sob_all_id). */
	public static function subObject(NuFormMeta $meta, string $id): ?array
	{
		foreach ($meta->objects as $o) if ($o['sob_all_type'] === 'subform' && (string)$o['sob_all_id'] === $id) return $o;
		return null;
	}

	/** A child table that may be shown: [table, column] as stored in the database, or null. */
	public static function childTable(string $key): ?array
	{
		if (!preg_match('~^t:([A-Za-z_][\w$]{0,63})\.([A-Za-z_][\w$]{0,63})$~', $key, $m)) return null;
		if (str_starts_with(strtolower($m[1]), 'zzzzsys_') || in_array(strtolower($m[1]), NuDemo::HIDDEN_TABLES, true)) return null;
		$db = nu_db();
		if (!$db->tableExists($m[1])) return null;
		foreach (array_keys($db->columns($m[1])) as $c) if (strcasecmp((string)$c, $m[2]) === 0) return [$m[1], (string)$c];
		return null;
	}

	/** ['sub', object] or ['table', [table, column]] of a list key, null when gone. */
	private static function listSource(NuFormMeta $meta, string $key): ?array
	{
		if (str_starts_with($key, 't:')) return ($t = self::childTable($key)) ? ['table', $t] : null;
		$o = self::subObject($meta, $key);
		return $o ? ['sub', $o] : null;
	}

	/** Title of a list: the sub-form's label, or the child table's name. */
	public static function listTitle(NuFormMeta $meta, string $key): string
	{
		$s = self::listSource($meta, $key);
		if (!$s) return $key;
		if ($s[0] === 'table') return ucfirst(str_replace('_', ' ', $s[1][0]));
		return trim(strip_tags((string)$s[1]['sob_all_label'])) ?: (string)$s[1]['sob_all_id'];
	}

	/** Client data of the list: {cols, lists: [{key, title}], form: url with __PK__, form_title, mode, sub (title of the first list)} or null. */
	public static function client(NuFormMeta $meta): ?array
	{
		$d = self::of($meta);
		if (!$d) return null;
		$lists = array_map(static fn($k) => ['key' => $k, 'title' => self::listTitle($meta, $k)], $d['subs'] ?? []);
		$url = ''; $ftitle = '';
		if (isset($d['form']) && NuAcl::can($d['form'])) {
			$f = NuFormMeta::load($d['form']);
			$url = nu_url('form', ['f' => $d['form'], 'r' => '__PK__', 'iframe' => '1', 'rdv' => '1']);
			$ftitle = $f ? $f->description() : '';
		}
		if (empty($d['cols']) && empty($d['own']) && !$lists && $url === '') return null;
		return ['cols' => !empty($d['cols']), 'own' => !empty($d['own']), 'lists' => $lists, 'sub' => $lists[0]['title'] ?? '', 'form' => $url, 'form_title' => $ftitle, 'mode' => $d['mode'] ?? 'many'];
	}

	/**
	 * What the designer can choose (design mode / side pane): sub-forms of the form and child tables – tables with a foreign key to the
	 * form's table, or with a column named like its primary key. [[key, label], …]
	 */
	public static function candidates(NuFormMeta $meta): array
	{
		$out = [];
		foreach ($meta->objects as $o) if ($o['sob_all_type'] === 'subform') $out[(string)$o['sob_all_id']] = (trim(strip_tags((string)$o['sob_all_label'])) ?: (string)$o['sob_all_id'])
			. ' (' . t('rd_kind_sub') . ')';
		$table = $meta->table(); $pk = $meta->primaryKey();
		if ($table === '' || $pk === '' || !preg_match('~^[\w$]+$~', $table)) return array_map(null, array_keys($out), array_values($out));
		$db = nu_db(); $found = [];
		// a column named like the primary key, or ending in _<primary key> (ord_sample_customer_id for sample_customer_id)
		$like = '%\\_' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pk);
		$likeMs = '%!_' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $pk);
		// The catalogue is asked once in ten minutes per table (kept in the session): every list page a designer opened ran two
		// catalogue statements, one of them over the columns of ALL tables of the database. A table change made through nuBuilder Next
		// in this session drops it (NuDB::noteWrite).
		$ck = strtolower($table . '|' . $pk);
		$live = session_status() === PHP_SESSION_ACTIVE;
		$kept = $live ? ($_SESSION[self::FOUND][$ck] ?? null) : null;
		if (is_array($kept) && (int)($kept['t'] ?? 0) > time() - 600 && is_array($kept['f'] ?? null)) $found = $kept['f'];
		else try {
			if ($db->type !== 'sqlsrv') {                               // SQLite: information_schema through NuSqlite
				foreach ($db->all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?', [$table]) as $r) $found[] = [(string)$r['t'],
					(string)$r['c']];
				foreach ($db->all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (COLUMN_NAME = ? OR COLUMN_NAME LIKE ?)', [$pk, $like]) as $r) $found[] = [(string)$r['t'],
					(string)$r['c']];
			} elseif ($db->type === 'sqlsrv') {
				foreach ($db->all("SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME = ? OR COLUMN_NAME LIKE ? ESCAPE '!'", [$pk, $likeMs]) as $r) $found[] = [(string)$r['t'],
					(string)$r['c']];
			}
			if ($live) $_SESSION[self::FOUND][$ck] = ['t' => time(), 'f' => $found];
		} catch (Throwable $ig) { nu_ignored($ig, 'catalogue not readable: sub-forms only'); }
		$subTables = [];
		foreach ($meta->objects as $o) if ($o['sob_all_type'] === 'subform' && ($sm = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']))) $subTables[strtolower($sm->table())] = true;
		foreach ($found as [$t, $c]) {
			$k = 't:' . $t . '.' . $c;
			if (strcasecmp($t, $table) === 0 || isset($out[$k]) || !self::validKey($k) || self::childTable($k) === null) continue;
			$out[$k] = $t . '.' . $c . ' (' . t(isset($subTables[strtolower($t)]) ? 'rd_kind_table_sub' : 'rd_kind_table') . ')';
		}
		return array_map(null, array_keys($out), array_values($out));
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
	 * One related list of a record (key = a key of the setting's subs, '' = the first): {key, title, cols: [labels], align, rows: [[cells]],
	 * total, more}. Empty rows when the list is not part of the setting or its source is gone.
	 */
	public static function related(NuFormMeta $meta, string $recordId, string $key = ''): array
	{
		$d = self::of($meta);
		$subs = $d['subs'] ?? [];
		if ($key === '') $key = $subs[0] ?? '';
		$empty = ['key' => $key, 'title' => '', 'cols' => [], 'align' => [], 'rows' => [], 'total' => 0, 'more' => false];
		if ($key === '' || !in_array($key, $subs, true) || $recordId === '' || $recordId === '-1') return $empty;
		$src = self::listSource($meta, $key);
		if (!$src) return $empty;
		if ($src[0] === 'table') return self::tableRows($src[1][0], $src[1][1], $recordId) + ['key' => $key, 'title' => self::listTitle($meta, $key)];
		$o = $src[1];
		$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
		if (!$sub) return $empty;
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
			'key' => $key,
			'title' => trim(strip_tags((string)$o['sob_all_label'])) ?: (string)$o['sob_all_id'],
			'cols' => array_map(static fn($c) => trim(strip_tags((string)$c['sob_all_label'])) ?: (string)$c['sob_all_id'], $cols),
			'align' => array_map(static fn($c) => self::isNumber($c) ? 'r' : 'l', $cols),
			'rows' => $out, 'total' => count($rows), 'more' => count($rows) > self::ROWS,
		];
	}

	/** Rows of a child table whose link column holds the record's id: the first COLS columns besides the link column, as text. */
	private static function tableRows(string $table, string $link, string $recordId): array
	{
		$db = nu_db();
		$cols = $db->columns($table);
		$show = [];
		foreach ($cols as $n => $c) {
			if (strcasecmp((string)$n, $link) === 0 || NuSecurity::secretName((string)$n) || !preg_match('~^[A-Za-z_][\w$]{0,63}$~', (string)$n)) continue;
			$show[(string)$n] = $c;
			if (count($show) >= self::COLS) break;
		}
		$q = static fn(string $n): string => $db->quoteName($n);
		$t = $q($table); $l = $q($link);
		$total = (int)$db->value("SELECT COUNT(*) FROM $t WHERE $l = ?", [$recordId]);
		$first = (string)array_key_first($show);
		$rows = $show && $total ? $db->page('SELECT ' . implode(', ', array_map($q, array_keys($show))) . " FROM $t WHERE $l = ?", [$recordId], $q($first), self::ROWS, 0) : [];
		$out = [];
		foreach ($rows as $r) {
			$cells = [];
			foreach (array_keys($show) as $n) {
				$v = (string)(NuSecurity::demoMask($n, $r[$n] ?? null) ?? '');
				$v = trim(preg_replace('~\s+~u', ' ', $v) ?? '');
				$cells[] = mb_strlen($v) > 200 ? mb_substr($v, 0, 199) . '…' : $v;
			}
			$out[] = $cells;
		}
		return [
			'cols' => array_map(static fn($n) => ucfirst(str_replace('_', ' ', (string)$n)), array_keys($show)),
			'align' => array_map(static fn($c) => $c['numeric'] ? 'r' : 'l', array_values($show)),
			'rows' => $out, 'total' => $total, 'more' => $total > self::ROWS,
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
