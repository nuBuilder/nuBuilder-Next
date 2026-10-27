<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Form spec (0.8.403): a short JSON description of a form that an AI can write – "Form with AI" (pages/nuformai.php).
 * The user copies a prompt (prompt(): this format, the tables and columns, the forms, and for a change the current form as spec),
 * pastes it into any AI chat and pastes the answer back. check() validates it and lists what happens; create() turns it into a
 * DRAFT – a new form with status Draft (sfo_status 0), or a form draft of an existing form (NuFormDraft) – never the live form.
 *
 * Format "nunext-form-1":
 *   {"format": "nunext-form-1",
 *    "form": {code, description, type, table, primary_key, browse_sql?, rows_per_page?, browse?: [{title, field, width?, align?, format?}]},
 *    "tabs": [{title, objects: [object …]}]}
 *   object: {id, type, label, input?, format?, width?, height?, top?, left?, same_row?, required?, unique?, readonly?, hidden?, access?,
 *            options?: [[value, text] …] | sql?, multiple?, lookup?: {form, code, description, description_width?},
 *            subform?: {form, foreign_key, type?: grid|form, add?, delete?}, html?, keep?: true, column?: {type, length?}}
 * Mapping to nuBuilder's rows (zzzzsys_form / _tab / _object / _browse) is done here (toSnapshot); objects keep their row ids, events
 * and code; keys an object of the spec does not have keep their value (update) or nuBuilder's defaults (new). Objects of types the spec
 * does not describe (calc, run, image, chart …) come as {"id", "type", "keep": true} and stay as they are.
 * Missing columns of the form's table are created for the draft (additive, nullable – NuDDL::typeForObject), a missing table as a
 * new table – both only when the user ticks it.
 */
final class NuFormSpec
{
	public const FORMAT = 'nunext-form-1';
	public const TYPES = ['input', 'textarea', 'select', 'lookup', 'subform', 'display', 'html', 'word'];
	public const FORM_TYPES = ['browseedit', 'browse', 'edit', 'launch', 'subform'];
	/** Input types (the builder's list, assets/js/nubuilder.js INPUT_TYPES) – also 'search', which nuBuilder knows. */
	public const INPUTS = ['text', 'nuDate', 'nuNumber', 'number', 'checkbox', 'button', 'date', 'datetime-local', 'time', 'email', 'tel', 'url', 'password', 'month', 'week',
		'color', 'range', 'file', 'nuAutoNumber', 'nuScroll', 'search'];
	public const MAX_OBJECTS = 300;

	// ================================================================= form → spec

	/** The spec of an existing form (live rows). */
	public static function fromForm(string $formId): ?array
	{
		$snap = NuFormSnapshot::capture($formId);
		return $snap ? self::fromSnapshot($snap) : null;
	}

	public static function fromSnapshot(array $snap): array
	{
		$f = $snap['form'];
		$tabs = $snap['tabs'];
		usort($tabs, static fn($a, $b) => (int)$a['syt_order'] <=> (int)$b['syt_order']);
		$byTab = [];
		foreach ($snap['objects'] as $o) $byTab[(string)$o['sob_all_zzzzsys_tab_id']][] = $o;
		$forms = self::formCodes();
		$out = ['format' => self::FORMAT, 'form' => array_filter([
			'code' => (string)$f['sfo_code'], 'description' => (string)$f['sfo_description'], 'type' => (string)$f['sfo_type'],
			'table' => (string)($f['sfo_table'] ?? ''), 'primary_key' => (string)($f['sfo_primary_key'] ?? ''),
			'browse_sql' => trim((string)($f['sfo_browse_sql'] ?? '')), 'rows_per_page' => (int)($f['sfo_browse_rows_per_page'] ?? 0) ?: null,
		], static fn($v) => $v !== '' && $v !== null)];
		$browse = $snap['browse'];
		usort($browse, static fn($a, $b) => (int)$a['sbr_order'] <=> (int)$b['sbr_order']);
		if ($browse) $out['form']['browse'] = array_map(static fn($b) => array_filter(['title' => (string)$b['sbr_title'], 'field' => (string)$b['sbr_display'],
			'width' => (int)$b['sbr_width'] ?: null, 'align' => ['l' => 'left', 'c' => 'center', 'r' => 'right'][(string)$b['sbr_align']] ?? null,
			'format' => (string)($b['sbr_format'] ?? '') ?: null], static fn($v) => $v !== null), $browse);
		$out['tabs'] = [];
		foreach ($tabs as $t) {
			$list = $byTab[(string)$t['zzzzsys_tab_id']] ?? [];
			usort($list, static fn($a, $b) => [(int)$a['sob_all_top'], (int)$a['sob_all_left'], (int)$a['sob_all_order']] <=> [(int)$b['sob_all_top'], (int)$b['sob_all_left'], (int)$b['sob_all_order']]);
			$out['tabs'][] = ['title' => trim(strip_tags((string)$t['syt_title'])), 'objects' => array_map(static fn($o) => self::objectSpec($o, $forms), $list)];
		}
		return $out;
	}

	private static function objectSpec(array $o, array $forms): array
	{
		$type = (string)$o['sob_all_type'];
		$s = ['id' => (string)$o['sob_all_id'], 'type' => $type];
		if (!in_array($type, self::TYPES, true)) return $s + ['label' => (string)$o['sob_all_label'], 'keep' => true];
		$s['label'] = (string)$o['sob_all_label'];
		if ($type === 'input') $s['input'] = (string)($o['sob_input_type'] ?? 'text') ?: 'text';
		if (trim((string)($o['sob_input_format'] ?? '')) !== '') $s['format'] = (string)$o['sob_input_format'];
		foreach (['top' => 'sob_all_top', 'left' => 'sob_all_left', 'width' => 'sob_all_width', 'height' => 'sob_all_height'] as $k => $c) $s[$k] = (int)$o[$c];
		$v = (string)$o['sob_all_validate'];
		if ($v === '1' || $v === '3') $s['required'] = true;
		if ($v === '2' || $v === '3') $s['unique'] = true;
		$a = (string)$o['sob_all_access'];
		if ($a === '1') $s['readonly'] = true; elseif ($a === '2') $s['hidden'] = true; elseif ($a !== '' && $a !== '0') $s['access'] = (int)$a;
		if ($type === 'select') {
			$sql = trim((string)($o['sob_select_sql'] ?? ''));
			if ($sql !== '' && !preg_match('/^\s*(select|with)\b/i', $sql)) {
				$p = explode('|', $sql);
				$s['options'] = [];
				for ($i = 0, $n = count($p); $i + 1 < $n; $i += 2) $s['options'][] = [$p[$i], $p[$i + 1]];
			} elseif ($sql !== '') $s['sql'] = $sql;
			if ((string)($o['sob_select_multiple'] ?? '') === '1') $s['multiple'] = true;
		}
		if ($type === 'display' && trim((string)($o['sob_display_sql'] ?? '')) !== '') $s['sql'] = (string)$o['sob_display_sql'];
		if ($type === 'html' && trim((string)($o['sob_html_code'] ?? '')) !== '') $s['html'] = (string)$o['sob_html_code'];
		if ($type === 'lookup') $s['lookup'] = array_filter(['form' => $forms[(string)$o['sob_lookup_zzzzsys_form_id']] ?? (string)$o['sob_lookup_zzzzsys_form_id'],
			'code' => (string)$o['sob_lookup_code'], 'description' => (string)$o['sob_lookup_description'],
			'description_width' => (int)$o['sob_lookup_description_width'] ?: null], static fn($x) => $x !== null && $x !== '');
		if ($type === 'subform') $s['subform'] = ['form' => $forms[(string)$o['sob_subform_zzzzsys_form_id']] ?? (string)$o['sob_subform_zzzzsys_form_id'],
			'foreign_key' => (string)$o['sob_subform_foreign_key'], 'type' => strtolower((string)$o['sob_subform_type']) === 'f' ? 'form' : 'grid',
			'add' => (string)$o['sob_subform_add'] !== '0', 'delete' => (string)$o['sob_subform_delete'] !== '0'];
		return $s;
	}

	/** id => code of every form (codes are what the spec uses for lookups and sub-forms). */
	private static function formCodes(): array
	{
		$out = [];
		foreach (nu_db()->all('SELECT zzzzsys_form_id, sfo_code FROM zzzzsys_form') as $r) $out[(string)$r['zzzzsys_form_id']] = (string)$r['sfo_code'];
		return $out;
	}

	/** A form by code or id: [id, row] or null. */
	private static function formRef(string $ref): ?array
	{
		$ref = trim($ref);
		if ($ref === '') return null;
		$r = nu_db()->one('SELECT * FROM zzzzsys_form WHERE sfo_code = ?', [$ref]) ?? nu_db()->one('SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$ref]);
		return $r ? [(string)$r['zzzzsys_form_id'], $r] : null;
	}

	// ================================================================= the prompt

	/** User tables with their columns (name type), for the prompt – at most $max tables, the form's own table first. */
	public static function schemaText(string $first = '', int $max = 80): string
	{
		$db = nu_db();
		$tables = match ($db->type) {
			'sqlsrv' => array_column($db->all("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_CATALOG = DB_NAME() ORDER BY TABLE_NAME"), 't'),
			'mysql'  => array_column($db->all('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'), 't'),
			default  => array_column($db->all("SELECT name AS t FROM sqlite_master WHERE type IN ('table','view') ORDER BY name"), 't'),
		};
		$tables = array_values(array_filter($tables, static fn($t) => stripos((string)$t, 'zzzzsys_') !== 0));
		if ($first !== '' && ($i = array_search($first, $tables, true)) !== false) { unset($tables[$i]); array_unshift($tables, $first); }
		$lines = [];
		foreach (array_slice($tables, 0, $max) as $t) {
			$cols = [];
			foreach ($db->columns((string)$t) as $n => $c) $cols[] = $n . ' ' . $c['type'] . ($c['auto'] ? ' auto' : '');
			$lines[] = '- ' . $t . ': ' . implode(', ', $cols);
		}
		if (count($tables) > $max) $lines[] = '- … ' . (count($tables) - $max) . ' more tables not listed';
		return $lines ? implode("\n", $lines) : '(no tables yet)';
	}

	/** Forms that lookups and sub-forms may refer to: code (type, table). */
	public static function formsText(): string
	{
		$lines = [];
		foreach (nu_db()->all("SELECT sfo_code, sfo_type, sfo_table, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' ORDER BY sfo_code") as $r) {
			$lines[] = '- ' . $r['sfo_code'] . ' (' . $r['sfo_type'] . ($r['sfo_table'] ? ', table ' . $r['sfo_table'] : '') . ') – ' . $r['sfo_description'];
		}
		return $lines ? implode("\n", array_slice($lines, 0, 200)) : '(none)';
	}

	/** The text for the AI chat. $formId: change an existing form; '' = new form. */
	public static function prompt(string $request, string $formId = ''): string
	{
		$spec = $formId !== '' ? self::fromForm($formId) : null;
		$db = nu_db()->type === 'sqlsrv' ? 'SQL Server' : 'MySQL / MariaDB';
		$p = "You design a data-entry form for nuBuilder Next (a front end for nuBuilder Forte 4.x, database: $db).\n"
			. ($spec ? "Change the existing form below as requested. Return the COMPLETE form in the same format (not only the changes).\n"
				: "Create a new form as requested.\n")
			. "Answer with ONE JSON object in a ```json code block and nothing else.\n\n"
			. "## The request\n" . trim($request) . "\n\n"
			. "## Format \"" . self::FORMAT . "\"\n" . self::formatHelp() . "\n\n"
			. "## Database tables (name: columns)\n" . self::schemaText($spec['form']['table'] ?? '') . "\n\n"
			. "## Existing forms (for lookup and sub-form references, by code)\n" . self::formsText() . "\n";
		if ($spec) $p .= "\n## The current form\n```json\n" . json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n";
		return $p;
	}

	public static function formatHelp(): string
	{
		return <<<'TXT'
{
  "format": "nunext-form-1",
  "form": {
    "code": "customers",                 // unique, letters / digits / _ ; keep it when changing a form
    "description": "Customers",          // title of the form
    "type": "browseedit",                // browseedit (list + edit), browse, edit, launch (no table), subform (used inside another form)
    "table": "customer",                 // database table; a table that does not exist yet is created from the objects
    "primary_key": "customer_id",        // its primary key column (text ids are created by nuBuilder)
    "rows_per_page": 20,
    "browse": [ {"title": "Name", "field": "cus_name", "width": 200, "align": "left", "format": ""} ]   // list columns
  },
  "tabs": [
    {"title": "Main", "objects": [
      {"id": "cus_name", "type": "input", "input": "text", "label": "Name", "width": 250, "required": true},
      {"id": "cus_since", "type": "input", "input": "nuDate", "format": "D|dd.mm.yyyy", "label": "Customer since", "width": 110},
      {"id": "cus_credit", "type": "input", "input": "nuNumber", "format": "N|1,000.00", "label": "Credit", "width": 120, "same_row": true},
      {"id": "cus_active", "type": "input", "input": "checkbox", "label": "Active"},
      {"id": "cus_country", "type": "select", "label": "Country", "options": [["US", "United States"], ["GB", "United Kingdom"]]},
      {"id": "cus_notes", "type": "textarea", "label": "Notes", "width": 400, "height": 80},
      {"id": "cus_rep_id", "type": "lookup", "label": "Sales rep", "lookup": {"form": "employees", "code": "emp_code", "description": "emp_name"}},
      {"id": "cus_orders", "type": "subform", "label": "Orders", "width": 700, "height": 250,
       "subform": {"form": "orderlines", "foreign_key": "orl_customer_id", "type": "grid", "add": true, "delete": true}},
      {"id": "cus_total", "type": "display", "label": "Total", "sql": "SELECT SUM(ord_total) FROM orders WHERE ord_customer_id = '#RECORD_ID#'"},
      {"id": "cus_hint", "type": "html", "html": "<b>Note</b>"},
      {"id": "cus_title", "type": "word", "label": "Address"}
    ]}
  ]
}
Rules:
- "id" of a data object = the column of the table it is saved in (unique in the form). New columns are created for objects whose column is missing.
- Types: input (input: text, number, nuNumber, nuDate, checkbox, email, password, time, color, url, tel, nuAutoNumber, button, nuScroll, file),
  textarea, select (options as [value, text] pairs, or "sql": "SELECT value, text FROM …"; "multiple": true), lookup (picks a record of another
  form; saves its primary key), subform (rows of another form of type subform – it must exist; foreign_key = its column that holds this
  record's primary key), display (read-only value from "sql"), html, word (a label).
- Optional: label, width / height (pixels), required, unique, readonly, hidden, "same_row": true (next to the object before),
  format for nuDate ("D|dd.mm.yyyy", "D|yyyy-mm-dd hh:nn") and nuNumber ("N|1,000.00", "N|€ 1,000.00").
- Layout: objects are placed in the given order, one per row; existing objects keep "top" / "left" – leave them unless asked to move.
- Objects with "keep": true are of types this format does not describe (calc, run, chart …): keep them as they are (they may move to another tab).
- Hash variables like #RECORD_ID# and #USER_ID# may be used in SQL. No comments in the JSON you return.
TXT;
	}

	// ================================================================= parse + check

	/** The JSON of an AI answer (a ```json block or the outermost {…}). @return array|string spec or error text */
	public static function parse(string $answer): array|string
	{
		$a = trim($answer);
		if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $a, $m)) $a = $m[1];
		else {
			$s = strpos($a, '{');
			$e = strrpos($a, '}');
			if ($s === false || $e === false || $e < $s) return t('fai_err_no_json');
			$a = substr($a, $s, $e - $s + 1);
		}
		$a = (string)preg_replace('~^\s*//.*$~m', '', $a);                     // comments the AI may have copied from the example …
		$a = (string)preg_replace('~(?<=[,\[\]{}"0-9el])[ \t]+//[^\n]*~', '', $a);   // … also after a value ("https://" has no space before //)
		$j = json_decode($a, true);
		if (!is_array($j)) return t('fai_err_json', json_last_error_msg());
		return $j;
	}

	/**
	 * Checks a spec and works out what create() would do.
	 * @return array{errors: list<string>, warnings: list<string>, plan: array} plan: new_table, columns (to add), objects added / changed /
	 *         removed / kept, tabs, snapshot (rows for the draft)
	 */
	public static function check(array $spec, string $formId = ''): array
	{
		$e = $w = [];
		$db = nu_db();
		if (($spec['format'] ?? self::FORMAT) !== self::FORMAT) $w[] = t('fai_warn_format', (string)($spec['format'] ?? ''));
		$f = is_array($spec['form'] ?? null) ? $spec['form'] : [];
		$code = trim((string)($f['code'] ?? ''));
		$desc = trim((string)($f['description'] ?? ''));
		$type = (string)($f['type'] ?? 'browseedit');
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $code)) $e[] = t('fai_err_code', $code);
		elseif (str_starts_with(strtolower($code), 'nu')) $e[] = t('builder_err_system');
		elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ? AND zzzzsys_form_id <> ?', [$code, $formId])) $e[] = t('builder_err_code_taken');
		if ($desc === '') $e[] = t('builder_err_code_desc');
		if (!in_array($type, self::FORM_TYPES, true)) $e[] = t('fai_err_form_type', $type);
		$table = $type === 'launch' ? '' : trim((string)($f['table'] ?? ''));
		$pk = $type === 'launch' ? '' : trim((string)($f['primary_key'] ?? ''));
		$newTable = false;
		$cols = [];
		if ($type !== 'launch') {
			if (!NuDDL::validName($table)) $e[] = t('builder_err_table_name');
			elseif (str_starts_with(strtolower($table), 'zzzzsys')) $e[] = t('builder_err_table_reserved');
			elseif ($db->tableExists($table)) {
				$cols = $db->columns($table);
				if ($pk === '' || NuRecord::column($cols, $pk) === null) $e[] = t('fai_err_pk', $pk, $table);
			} else {
				$newTable = true;
				if ($pk === '') $pk = $table . '_id';
				if (!NuDDL::validName($pk)) $e[] = t('builder_err_column_name', $pk);
			}
		}

		$base = $formId !== '' ? NuFormSnapshot::capture($formId) : null;
		if ($formId !== '' && !$base) $e[] = t('form_err_not_found');
		$baseObj = [];
		foreach ($base['objects'] ?? [] as $o) $baseObj[strtolower((string)$o['sob_all_id'])] = $o;

		$tabs = is_array($spec['tabs'] ?? null) ? $spec['tabs'] : [];
		if (!$tabs) $e[] = t('builder_err_one_tab');
		$seen = [];
		$addCols = [];
		$count = 0;
		$tabTitles = [];
		foreach ($tabs as $ti => $t) {
			if (!is_array($t) || trim((string)($t['title'] ?? '')) === '') { $e[] = t('fai_err_tab', $ti + 1); continue; }
			$tk = mb_strtolower(trim((string)$t['title']));
			if (isset($tabTitles[$tk])) $e[] = t('fai_err_tab_dup', trim((string)$t['title']));
			$tabTitles[$tk] = true;
			foreach (is_array($t['objects'] ?? null) ? $t['objects'] : [] as $o) {
				$count++;
				if (!is_array($o)) { $e[] = t('fai_err_object', '?'); continue; }
				$id = trim((string)($o['id'] ?? ''));
				$ot = (string)($o['type'] ?? '');
				if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id)) { $e[] = t('builder_err_id') . ' (' . $id . ')'; continue; }
				if (isset($seen[strtolower($id)])) { $e[] = t('builder_err_id_taken', $id); continue; }
				$seen[strtolower($id)] = true;
				if (!empty($o['keep'])) {
					if (!isset($baseObj[strtolower($id)])) $e[] = t('fai_err_keep', $id);
					continue;
				}
				if (!in_array($ot, self::TYPES, true)) { $e[] = t('fai_err_type', $id, $ot); continue; }
				if ($ot === 'input' && isset($o['input']) && !in_array((string)$o['input'], self::INPUTS, true)) $e[] = t('fai_err_input', $id, (string)$o['input']);
				if ($ot === 'select' && !isset($o['options']) && !isset($o['sql']) && !isset($baseObj[strtolower($id)])) $e[] = t('fai_err_select', $id);
				if (in_array($ot, ['select', 'display'], true) && isset($o['sql']) && !preg_match('/^\s*(select|with)\b/i', (string)$o['sql'])) $e[] = t('fai_err_sql', $id);
				if ($ot === 'lookup') {
					$l = is_array($o['lookup'] ?? null) ? $o['lookup'] : [];
					$ref = self::formRef((string)($l['form'] ?? ''));
					if (!$ref && !isset($baseObj[strtolower($id)])) $e[] = t('fai_err_ref', $id, (string)($l['form'] ?? ''));
					elseif ($ref) {
						$lc = trim((string)($ref[1]['sfo_table'] ?? '')) !== '' ? $db->columns((string)$ref[1]['sfo_table']) : [];
						foreach (['code', 'description'] as $k) if (!empty($l[$k]) && $lc && NuRecord::column($lc, (string)$l[$k]) === null) $w[] = t('fai_warn_lookup_col', $id, (string)$l[$k], (string)$ref[1]['sfo_table']);
					}
				}
				if ($ot === 'subform') {
					$sfs = is_array($o['subform'] ?? null) ? $o['subform'] : [];
					$ref = self::formRef((string)($sfs['form'] ?? ''));
					if (!$ref && !isset($baseObj[strtolower($id)])) $e[] = t('fai_err_ref', $id, (string)($sfs['form'] ?? ''));
					elseif ($ref) {
						$sc = trim((string)($ref[1]['sfo_table'] ?? '')) !== '' ? $db->columns((string)$ref[1]['sfo_table']) : [];
						$fk = trim((string)($sfs['foreign_key'] ?? ''));
						if ($fk === '' || ($sc && NuRecord::column($sc, $fk) === null)) $e[] = t('fai_err_fk', $id, $fk, (string)$ref[1]['sfo_table']);
					}
					continue;                                    // a sub-form has no column of its own
				}
				// data objects need a column: missing ones are added (or come with the new table)
				if (in_array($ot, ['input', 'textarea', 'select', 'lookup'], true) && ($ot !== 'input' || ($o['input'] ?? '') !== 'button') && $table !== '' && strcasecmp($id, $pk) !== 0) {
					if ($newTable || NuRecord::column($cols, $id) === null) {
						if (!NuDDL::validName($id)) { $e[] = t('builder_err_column_name', $id); continue; }
						$ct = is_array($o['column'] ?? null) && in_array((string)($o['column']['type'] ?? ''), NuDDL::TYPES, true)
							? [(string)$o['column']['type'], (string)($o['column']['length'] ?? '')]
							: (NuDDL::typeForObject(self::rowFor($o, [])) ?? ['text', '255']);
						$addCols[] = ['name' => $id, 'type' => $ct[0], 'length' => $ct[1]];
					}
				}
			}
		}
		if ($count > self::MAX_OBJECTS) $e[] = t('fai_err_many', self::MAX_OBJECTS);
		$removed = array_values(array_diff(array_map(static fn($o) => (string)$o['sob_all_id'], $base['objects'] ?? []),
			array_filter(array_map(static fn($o) => (string)$o['sob_all_id'], $base['objects'] ?? []), static fn($id) => isset($seen[strtolower($id)]))));
		foreach (is_array($f['browse'] ?? null) ? $f['browse'] : [] as $b) {
			if (!is_array($b) || trim((string)($b['field'] ?? '')) === '') $e[] = t('fai_err_browse');
		}

		$plan = ['new_table' => $newTable ? ['table' => $table, 'pk' => $pk] : null, 'columns' => $addCols, 'removed' => $removed,
			'added' => [], 'changed' => [], 'moved' => 0, 'form' => [], 'browse' => 0, 'tabs' => 0, 'snapshot' => null];
		if (!$e) {
			try {
				$snap = self::toSnapshot($spec, $base, $formId !== '' ? $formId : 'nuFAINEW', $table, $pk);
				$plan['snapshot'] = $snap;
				if ($base) {
					$d = NuFormSnapshot::diff($base, $snap);
					$plan['added'] = array_column($d['objects']['added'], 'sob_all_id');
					$plan['changed'] = array_map(static fn($c) => ['id' => (string)$c['sob_all_id'], 'fields' => array_column($c['fields'], 0)], $d['objects']['changed']);
					$plan['moved'] = (int)$d['objects']['moved'] + ($d['objects']['order'] ? 1 : 0);
					$plan['form'] = array_column($d['form'], 0);
					$plan['browse'] = (int)$d['counts']['browse'];
					$plan['tabs'] = (int)$d['counts']['tabs'];
				} else $plan['added'] = array_map(static fn($o) => (string)$o['sob_all_id'], $snap['objects']);
				if ($formId !== '') {
					$v = NuFormDraft::validate($formId, $snap, $base);
					$e = array_merge($e, $v['errors']);
					foreach ($v['warnings'] as $x) if (!str_contains($x, (string)$table) || !$addCols) $w[] = $x;   // missing columns: listed in the plan
				}
			} catch (Throwable $x) {
				$e[] = $x->getMessage();
			}
		}
		return ['errors' => array_values(array_unique($e)), 'warnings' => array_values(array_unique($w)), 'plan' => $plan];
	}

	// ================================================================= spec → rows

	/** nuBuilder's object row for a spec object, on top of $row (existing object) or the defaults. */
	private static function rowFor(array $o, array $row): array
	{
		$type = (string)($o['type'] ?? ($row['sob_all_type'] ?? 'input'));
		$row['sob_all_type'] = $type;
		$row['sob_all_id'] = (string)($o['id'] ?? ($row['sob_all_id'] ?? ''));
		if (array_key_exists('label', $o)) $row['sob_all_label'] = (string)$o['label'];
		$row['sob_all_label'] ??= $type === 'word' ? '' : ucwords(str_replace('_', ' ', $row['sob_all_id']));
		if ($type === 'input') {
			if (isset($o['input'])) $row['sob_input_type'] = (string)$o['input'];
			$row['sob_input_type'] = (string)($row['sob_input_type'] ?? 'text') ?: 'text';
			if (!array_key_exists('format', $o) && !isset($row['sob_input_format'])) {
				$row['sob_input_format'] = ['nuDate' => 'D|dd.mm.yyyy', 'nuNumber' => 'N|1,000.00'][$row['sob_input_type']] ?? null;
			}
		} else $row['sob_input_type'] = null;
		if (array_key_exists('format', $o)) $row['sob_input_format'] = (string)$o['format'] !== '' ? (string)$o['format'] : null;
		$defW = match ($type) { 'textarea', 'html' => 400, 'subform' => 700, 'word' => 150, 'lookup' => 120, default => ($row['sob_input_type'] ?? '') === 'checkbox' ? 20 : 250 };
		$defH = match ($type) { 'textarea' => 80, 'html' => 60, 'subform' => 250, default => ($row['sob_input_type'] ?? '') === 'checkbox' ? 20 : 22 };
		$row['sob_all_width'] = isset($o['width']) ? max(10, (int)$o['width']) : (int)($row['sob_all_width'] ?? $defW);
		$row['sob_all_height'] = isset($o['height']) ? max(10, (int)$o['height']) : (int)($row['sob_all_height'] ?? $defH);
		if (array_key_exists('required', $o) || array_key_exists('unique', $o) || !isset($row['sob_all_validate'])) {
			$req = array_key_exists('required', $o) ? !empty($o['required']) : in_array((string)($row['sob_all_validate'] ?? ''), ['1', '3'], true);
			$uni = array_key_exists('unique', $o) ? !empty($o['unique']) : in_array((string)($row['sob_all_validate'] ?? ''), ['2', '3'], true);
			$row['sob_all_validate'] = $req && $uni ? '3' : ($req ? '1' : ($uni ? '2' : '0'));
		}
		if (isset($o['access'])) $row['sob_all_access'] = (string)max(0, min(4, (int)$o['access']));
		elseif (array_key_exists('readonly', $o) || array_key_exists('hidden', $o)) $row['sob_all_access'] = !empty($o['hidden']) ? '2' : (!empty($o['readonly']) ? '1' : '0');
		$row['sob_all_access'] ??= '0';
		$row['sob_all_cloneable'] ??= '1';
		$row['sob_all_align'] ??= $type === 'input' && in_array($row['sob_input_type'], ['number', 'nuNumber'], true) ? 'right' : 'left';
		if ($type === 'select') {
			if (isset($o['options']) && is_array($o['options'])) {
				$parts = [];
				foreach ($o['options'] as $op) {
					[$v, $tx] = is_array($op) ? [(string)($op[0] ?? ''), (string)($op[1] ?? $op[0] ?? '')] : [(string)$op, (string)$op];
					$parts[] = str_replace('|', '/', $v);
					$parts[] = str_replace('|', '/', $tx);
				}
				$row['sob_select_sql'] = implode('|', $parts);
			} elseif (isset($o['sql'])) $row['sob_select_sql'] = (string)$o['sql'];
			if (array_key_exists('multiple', $o)) $row['sob_select_multiple'] = !empty($o['multiple']) ? '1' : '0';
		}
		if ($type === 'display' && isset($o['sql'])) $row['sob_display_sql'] = (string)$o['sql'];
		if ($type === 'html' && isset($o['html'])) $row['sob_html_code'] = (string)$o['html'];
		if ($type === 'lookup' && is_array($o['lookup'] ?? null)) {
			$l = $o['lookup'];
			$ref = self::formRef((string)($l['form'] ?? ''));
			if ($ref) { $row['sob_lookup_zzzzsys_form_id'] = $ref[0]; $row['sob_lookup_table'] = (string)($ref[1]['sfo_table'] ?? ''); }
			if (isset($l['code'])) $row['sob_lookup_code'] = (string)$l['code'];
			if (isset($l['description'])) $row['sob_lookup_description'] = (string)$l['description'];
			$row['sob_lookup_description_width'] = isset($l['description_width']) ? (int)$l['description_width'] : (int)($row['sob_lookup_description_width'] ?? 0 ?: 200);
			$row['sob_lookup_autocomplete'] ??= '0';
		}
		if ($type === 'subform' && is_array($o['subform'] ?? null)) {
			$sf = $o['subform'];
			$ref = self::formRef((string)($sf['form'] ?? ''));
			if ($ref) { $row['sob_subform_zzzzsys_form_id'] = $ref[0]; $row['sob_subform_table'] = (string)($ref[1]['sfo_table'] ?? '') ?: null; }
			if (isset($sf['foreign_key'])) $row['sob_subform_foreign_key'] = (string)$sf['foreign_key'];
			if (isset($sf['type'])) $row['sob_subform_type'] = (string)$sf['type'] === 'form' ? 'f' : 'g';
			$row['sob_subform_type'] ??= 'g';
			if (array_key_exists('add', $sf)) $row['sob_subform_add'] = !empty($sf['add']) ? '1' : '0';
			if (array_key_exists('delete', $sf)) $row['sob_subform_delete'] = !empty($sf['delete']) ? '1' : '0';
			$row['sob_subform_add'] ??= '1';
			$row['sob_subform_delete'] ??= '1';
		}
		return $row;
	}

	/** The rows of the form as NuFormSnapshot (v2) – on top of $base (change) or new. */
	public static function toSnapshot(array $spec, ?array $base, string $formId, string $table, string $pk): array
	{
		$f = $spec['form'];
		$type = (string)($f['type'] ?? 'browseedit');
		$form = $base['form'] ?? ['zzzzsys_form_id' => $formId, 'sfo_status' => '0', 'sfo_browse_rows_per_page' => 20];
		$form['sfo_code'] = trim((string)$f['code']);
		$form['sfo_description'] = trim((string)$f['description']);
		$form['sfo_type'] = $type;
		$form['sfo_table'] = $table !== '' ? $table : null;
		$form['sfo_primary_key'] = $pk !== '' ? $pk : null;
		if (isset($f['browse_sql']) && trim((string)$f['browse_sql']) !== '') $form['sfo_browse_sql'] = trim((string)$f['browse_sql']);
		elseif (!$base || trim((string)($form['sfo_browse_sql'] ?? '')) === '' || ($base['form']['sfo_table'] ?? '') !== $table) $form['sfo_browse_sql'] = $table !== '' ? 'SELECT * FROM ' . $table : null;
		if (isset($f['rows_per_page'])) $form['sfo_browse_rows_per_page'] = max(1, min(500, (int)$f['rows_per_page']));
		if (!$base) $form['sfo_breadcrumb_title'] = '|' . $form['sfo_description'];

		// tabs: matched by title
		$baseTabs = [];
		foreach ($base['tabs'] ?? [] as $t) $baseTabs[mb_strtolower(trim(strip_tags((string)$t['syt_title'])))] = $t;
		$baseObj = [];
		foreach ($base['objects'] ?? [] as $o) $baseObj[strtolower((string)$o['sob_all_id'])] = $o;
		$tabs = $objects = [];
		foreach ($spec['tabs'] as $ti => $t) {
			$key = mb_strtolower(trim((string)$t['title']));
			$tab = $baseTabs[$key] ?? ['zzzzsys_tab_id' => nu_id(), 'syt_zzzzsys_form_id' => $formId, 'syt_title' => trim((string)$t['title']), 'syt_access' => '0'];
			unset($baseTabs[$key]);
			$tab['syt_order'] = ($ti + 1) * 10;
			$tabs[] = $tab;
			$prev = null;
			$shift = 0;
			$order = 0;
			foreach ($t['objects'] ?? [] as $o) {
				$k = strtolower((string)$o['id']);
				$old = $baseObj[$k] ?? null;
				$row = !empty($o['keep']) && $old ? $old : self::rowFor($o, $old ?? []);
				$row['zzzzsys_object_id'] ??= nu_id();
				$row['sob_all_zzzzsys_form_id'] = $formId;
				$row['sob_all_table'] = $table;
				$sameTab = $old && (string)($old['sob_all_zzzzsys_tab_id'] ?? '') === (string)$tab['zzzzsys_tab_id'];
				$row['sob_all_zzzzsys_tab_id'] = (string)$tab['zzzzsys_tab_id'];
				// order: kept for objects that stay on their tab, after the object before for new / moved ones
				if ($sameTab) $order = max($order, (int)$old['sob_all_order']);
				else $order++;
				$row['sob_all_order'] = $sameTab ? (int)$old['sob_all_order'] : $order;
				// placement: given > kept (moved down by objects inserted above) > below / next to the object before
				// top / left of the spec count only when they differ from the stored ones (the export lists them for every object)
				$explicit = isset($o['top'], $o['left']) && empty($o['same_row'])
					&& (!$old || (int)$o['top'] !== (int)$old['sob_all_top'] || (int)$o['left'] !== (int)$old['sob_all_left']);
				if ($explicit) {
					$row['sob_all_top'] = (int)$o['top'];
					$row['sob_all_left'] = (int)$o['left'];
				} elseif ($sameTab && empty($o['same_row'])) {
					$row['sob_all_top'] = (int)$old['sob_all_top'] + $shift;
				} else {
					$label = in_array((string)$row['sob_all_type'], ['word', 'html', 'subform'], true) ? 0 : 150;
					if ($prev && !empty($o['same_row'])) {
						$row['sob_all_top'] = (int)$prev['sob_all_top'];
						$row['sob_all_left'] = (int)$prev['sob_all_left'] + (int)$prev['sob_all_width'] + 30 + $label;
					} else {
						$top = $prev ? (int)$prev['sob_all_top'] + (int)$prev['sob_all_height'] + 12 : 20;
						if ($prev && $old === null) $shift += (int)$row['sob_all_height'] + 12;
						$row['sob_all_top'] = $top;
						$row['sob_all_left'] = 170;
					}
				}
				$row['sob_all_left'] ??= 170;
				$objects[] = $row;
				$prev = $row;
			}
		}
		// events / code of objects that stay; form hooks, SQL builder rows stay as they are
		$ids = array_flip(array_map(static fn($o) => (string)$o['zzzzsys_object_id'], $objects));
		$events = array_values(array_filter($base['events'] ?? [], static fn($ev) => isset($ids[(string)$ev['sev_zzzzsys_object_id']])));
		$php = array_values(array_filter($base['php'] ?? [], static fn($p) => !str_ends_with((string)$p['zzzzsys_php_id'], '_AB') || isset($ids[substr((string)$p['zzzzsys_php_id'], 0, -3)])));

		// list columns: from the spec, else as before, else the first data objects
		$browse = $base['browse'] ?? [];
		if (is_array($f['browse'] ?? null)) {
			$old = [];
			foreach ($browse as $b) $old[strtolower((string)$b['sbr_display'])] = $b;
			$browse = [];
			foreach ($f['browse'] as $i => $b) {
				$field = trim((string)$b['field']);
				$row = $old[strtolower($field)] ?? ['zzzzsys_browse_id' => nu_id(), 'sbr_zzzzsys_form_id' => $formId];
				$row['sbr_display'] = $field;
				$row['sbr_title'] = (string)($b['title'] ?? $field);
				$row['sbr_width'] = (int)($b['width'] ?? ($row['sbr_width'] ?? 150));
				$row['sbr_align'] = ['left' => 'l', 'center' => 'c', 'right' => 'r'][(string)($b['align'] ?? '')] ?? ($row['sbr_align'] ?? 'l');
				$row['sbr_format'] = isset($b['format']) ? ((string)$b['format'] ?: null) : ($row['sbr_format'] ?? null);
				$row['sbr_order'] = ($i + 1) * 10;
				$browse[] = $row;
			}
		} elseif (!$base && !in_array($type, ['edit', 'launch', 'subform'], true)) {
			$n = 0;
			foreach ($objects as $o) {
				if ($n >= 6 || !in_array((string)$o['sob_all_type'], ['input', 'select', 'lookup'], true) || ($o['sob_input_type'] ?? '') === 'button') continue;
				$browse[] = ['zzzzsys_browse_id' => nu_id(), 'sbr_zzzzsys_form_id' => $formId, 'sbr_title' => (string)$o['sob_all_label'], 'sbr_display' => (string)$o['sob_all_id'],
					'sbr_align' => ($o['sob_all_align'] ?? 'left') === 'right' ? 'r' : 'l', 'sbr_format' => $o['sob_input_format'] ?? null, 'sbr_order' => ++$n * 10, 'sbr_width' => min(250, max(80, (int)$o['sob_all_width']))];
			}
		}
		return ['v' => NuFormSnapshot::VERSION, 'form' => $form, 'tabs' => $tabs, 'objects' => $objects, 'events' => $events, 'browse' => $browse,
			'php' => $php, 'selects' => $base['selects'] ?? [], 'clauses' => $base['clauses'] ?? []];
	}

	// ================================================================= create the draft

	/**
	 * A checked spec becomes a draft. New form: tables / columns, then the form rows with status Draft. Existing form: a form draft
	 * (NuFormDraft::start + save) and the missing columns. @return array{form_id: string, draft_id: string}
	 */
	public static function create(array $spec, string $formId, bool $ddlOk, string $note): array
	{
		$r = self::check($spec, $formId);
		if ($r['errors']) throw new RuntimeException(implode("\n", $r['errors']));
		$plan = $r['plan'];
		if (($plan['new_table'] || $plan['columns']) && !$ddlOk) throw new RuntimeException(t('fai_err_ddl_ok'));
		if (!NuFormDraft::canEdit()) throw new RuntimeException(t('err_forbidden'));
		$db = nu_db();
		$f = $spec['form'];
		$table = $plan['new_table']['table'] ?? ((string)($f['type'] ?? '') === 'launch' ? '' : trim((string)$f['table']));
		$pk = $plan['new_table']['pk'] ?? ((string)($f['type'] ?? '') === 'launch' ? '' : trim((string)$f['primary_key']));

		if ($formId === '') {
			if ($plan['new_table']) NuDDL::createTable($table, $pk, $plan['columns'], false);   // DDL commits by itself on MySQL – before the rows
			else foreach ($plan['columns'] as $c) NuDDL::addColumn($table, $c['name'], $c['type'], $c['length']);
			$newId = nu_id();
			$snap = self::toSnapshot($spec, null, $newId, $table, $pk);
			$snap['form']['zzzzsys_form_id'] = $newId;
			$snap['form']['sfo_status'] = '0';                                  // Draft: designers only, until published
			$db->transaction(static function (NuDB $db) use ($newId, $snap) {
				$db->insert('zzzzsys_form', ['zzzzsys_form_id' => $newId, 'sfo_code' => $snap['form']['sfo_code'], 'sfo_type' => $snap['form']['sfo_type'], 'sfo_status' => '0']);
				NuFormSnapshot::apply($db, $newId, $snap);
			});
			NuFormMeta::forget($newId);
			NuFormHistory::snapshot($newId, 'created with AI');
			NuLog::write('form_ai_created', (string)$snap['form']['sfo_code'], $newId, array_filter(['table' => $plan['new_table'] ? $table : null,
				'columns' => array_column($plan['columns'], 'name') ?: null, 'note' => mb_substr($note, 0, 200)]));
			return ['form_id' => $newId, 'draft_id' => ''];
		}

		$d = NuFormDraft::start($formId, mb_substr('AI: ' . $note, 0, 1000));
		$draftId = (string)$d['zzzzsys_nunext_form_draft_id'];
		try {
			foreach ($plan['columns'] as $c) {
				NuDDL::addColumn($table, $c['name'], $c['type'], $c['length']);
				NuFormDraft::addDdl($draftId, $table, $c['name'], $c['type'], $c['length']);
			}
			$snap = self::toSnapshot($spec, NuFormSnapshot::capture($formId), $formId, $table, $pk);
			NuFormDraft::save($draftId, (int)$d['rev'], $snap, 'AI');
		} catch (Throwable $x) {
			try { NuFormDraft::discard($draftId); } catch (Throwable) { /* keep the first error */ }
			throw $x;
		}
		NuLog::write('form_ai_draft', (string)$spec['form']['code'], $formId, array_filter(['draft' => $draftId, 'columns' => array_column($plan['columns'], 'name') ?: null,
			'note' => mb_substr($note, 0, 200)]));
		return ['form_id' => $formId, 'draft_id' => $draftId];
	}
}
