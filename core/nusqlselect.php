<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * SQL builder queries – nuBuilder's zzzzsys_select (form "nuselect") with its clauses zzzzsys_select_clause.
 *   sse_code, sse_description   name
 *   sse_json                    the graphic: {"tables": [{id: "box…", position: {top, left}, tablename, alias, checkall, checkboxes: [bool per column]}],
 *                               "joins": {"field_<col>_<box>--field_<col>_<box>": "" | "LEFT"}}
 *   sse_sql                     the SQL (made by the builder; sse_edit = 1: edited by hand, the builder leaves it alone)
 *   clauses                     ssc_type 1 WHERE · 2 GROUP BY · 3 ORDER BY · 4 HAVING, ssc_field, ssc_clause, ssc_sort (ASC/DESC), ssc_order
 * Used as report data source "SQL:<id>". Rights: nuBuilder's system form "nuselect".
 */
final class NuSqlSelect
{
	public const TABLE  = 'zzzzsys_select';
	public const CLAUSE = 'zzzzsys_select_clause';
	public const TYPES  = ['1' => 'WHERE', '2' => 'GROUP BY', '3' => 'ORDER BY', '4' => 'HAVING'];

	public static function available(): bool
	{
		try {
			return nu_db()->tableExists(self::TABLE);
		} catch (Throwable) {
			return false;
		}
	}

	public static function load(string $id): ?array
	{
		return $id === '' ? null : nu_db()->one('SELECT * FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$id]);
	}

	/** Clause rows, sorted by type and order. */
	public static function clauses(string $id): array
	{
		$rows = nu_db()->all('SELECT zzzzsys_select_clause_id, ssc_type, ssc_field, ssc_clause, ssc_sort, ssc_order FROM zzzzsys_select_clause WHERE ssc_zzzzsys_select_id = ?', [$id]);
		usort($rows, static fn($a, $b) => [(int)$a['ssc_type'], (float)$a['ssc_order']] <=> [(int)$b['ssc_type'], (float)$b['ssc_order']]);
		return $rows;
	}

	/**
	 * Saves the query and replaces its clauses. $in: sse_code, sse_description, sse_json, sse_sql, sse_edit, clauses (list of
	 * [ssc_type, ssc_field, ssc_clause, ssc_sort, ssc_order]). @return array{0: string, 1: array} [id, errors]
	 */
	public static function save(array $in, string $id = ''): array
	{
		$db = nu_db();
		$row = [
			'sse_code' => mb_substr(trim((string)($in['sse_code'] ?? '')), 0, 50),
			'sse_description' => mb_substr(trim((string)($in['sse_description'] ?? '')), 0, 300),
			'sse_json' => (string)($in['sse_json'] ?? ''),
			'sse_sql' => (string)($in['sse_sql'] ?? ''),
			'sse_edit' => (string)($in['sse_edit'] ?? '') === '1' ? '1' : '0',
		];
		$errors = [];
		if ($row['sse_description'] === '') $errors['sse_description'] = t('err_required');
		if ($row['sse_code'] !== '' && (int)$db->value('SELECT COUNT(*) FROM zzzzsys_select WHERE sse_code = ? AND zzzzsys_select_id <> ?', [$row['sse_code'], $id])) $errors['sse_code'] = t('sqb_err_code_taken');
		if ($row['sse_json'] !== '' && !is_array(json_decode($row['sse_json'], true))) $errors['sse_json'] = t('sqb_err_json');
		if (trim($row['sse_sql']) === '') $errors['sse_sql'] = t('sqb_err_empty');
		if ($errors) return [$id, $errors];

		$clauses = [];
		foreach ((array)($in['clauses'] ?? []) as $c) {
			if (!is_array($c)) continue;
			$type = (string)($c['ssc_type'] ?? '');
			if (!isset(self::TYPES[$type]) || trim((string)($c['ssc_field'] ?? '')) === '') continue;
			$sort = strtoupper((string)($c['ssc_sort'] ?? ''));
			$clauses[] = ['ssc_type' => $type, 'ssc_field' => mb_substr((string)$c['ssc_field'], 0, 500), 'ssc_clause' => mb_substr((string)($c['ssc_clause'] ?? ''), 0, 500),
				'ssc_sort' => in_array($sort, ['ASC', 'DESC'], true) ? $sort : '', 'ssc_order' => mb_substr((string)($c['ssc_order'] ?? ''), 0, 500)];
		}

		$db->transaction(static function (NuDB $db) use (&$id, $row, $clauses) {
			if ($id === '') {
				$id = nu_id();
				$db->insert(self::TABLE, ['zzzzsys_select_id' => $id] + $row);
			} else {
				$db->update(self::TABLE, $row, 'zzzzsys_select_id', $id);
			}
			// keep the clause rows (and their ids) that did not change, reuse the others – as nuBuilder's sub-form, which only
			// writes edited rows; a save without a change leaves zzzzsys_select_clause as it was (0.8.364)
			$left = [];
			foreach ($db->all('SELECT * FROM zzzzsys_select_clause WHERE ssc_zzzzsys_select_id = ? ORDER BY zzzzsys_select_clause_id', [$id]) as $o) $left[(string)$o['zzzzsys_select_clause_id']] = $o;
			$same = static fn(array $o, array $c): bool => !array_filter(array_keys($c), static fn($k) => (string)($o[$k] ?? '') !== (string)($c[$k] ?? ''));
			$todo = [];
			foreach ($clauses as $c) {
				$hit = null;
				foreach ($left as $oid => $o) if ($same($o, $c)) { $hit = $oid; break; }
				if ($hit !== null) unset($left[$hit]); else $todo[] = $c;
			}
			foreach ($todo as $c) {
				$oid = array_key_first($left);
				if ($oid !== null) { unset($left[$oid]); $db->update(self::CLAUSE, $c, 'zzzzsys_select_clause_id', (string)$oid); }
				else $db->insert(self::CLAUSE, ['zzzzsys_select_clause_id' => nu_id(), 'ssc_zzzzsys_select_id' => $id] + $c);
			}
			foreach (array_keys($left) as $oid) $db->delete(self::CLAUSE, 'zzzzsys_select_clause_id', (string)$oid);
		});
		return [$id, []];
	}

	public static function delete(string $id): void
	{
		nu_db()->transaction(static function (NuDB $db) use ($id) {
			$db->delete(self::CLAUSE, 'ssc_zzzzsys_select_id', $id);
			$db->delete(self::TABLE, 'zzzzsys_select_id', $id);
		});
	}

	/** Reports that print from this query. */
	public static function usage(string $id): array
	{
		if (!NuReport::available()) return [];
		return nu_db()->all('SELECT zzzzsys_report_id, sre_code, sre_description FROM zzzzsys_report WHERE sre_zzzzsys_php_id = ? ORDER BY sre_code', ['SQL:' . $id]);
	}

	/** Tables (with nuBuilder's own when $system) and their columns in database order: name => [column, …]. */
	public static function schema(bool $system = false): array
	{
		$db = nu_db();
		$out = [];
		foreach (NuReport::tables($system) as $t) $out[$t] = array_keys($db->columns($t));
		return $out;
	}

	/**
	 * Runs the query for the preview: at most $limit rows, hash variables with empty or current values. Only SELECT / WITH statements.
	 * @return array{columns: string[], rows: array, more: bool}
	 */
	/** Tables a demo visitor's preview may not read (passwords, sessions, keys, mail server settings). */
	public const DEMO_HIDDEN = NuDemo::HIDDEN_TABLES;

	/**
	 * Runs a SELECT typed in the SQL builder (first $limit rows). Since 0.8.207: exactly one reading statement (NuDemo::readOnlySql –
	 * no INTO, locks, LOAD_FILE, writes after a WITH), inside a rolled-back read-only transaction with a 15 s time limit
	 * (NuDB::readOnly). Demo visitors cannot read DEMO_HIDDEN / zzzzsys_nunext_* tables; password hashes are masked for them.
	 */
	public static function preview(string $sql, int $limit = 50): array
	{
		$sql = rtrim(trim($sql), "; \n\r\t");
		if (!NuDemo::readOnlySql(NuHash::replace($sql, [], NuHash::RAW))) throw new RuntimeException(t('sqb_err_select_only'));
		$sql = NuHash::replace($sql, NuHash::plain(), NuHash::SQL);
		if (!NuDemo::readOnlySql($sql)) throw new RuntimeException(t('sqb_err_select_only'));
		if (NuDemo::active() && NuDemo::touchesHidden($sql)) throw new RuntimeException(t('demo_not_available'));   // 0.8.341: every word, not only FROM / JOIN
		$db = nu_db();
		return $db->readOnly(static function (NuDB $db) use ($sql, $limit): array {
			$st = $db->run($sql);
			$cols = [];
			for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = (string)($st->getColumnMeta($i)['name'] ?? $i);
			$rows = [];
			$more = false;
			while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
				if (count($rows) >= $limit) { $more = true; break; }
				$row = [];
				foreach ($r as $i => $v) $row[] = $v === null ? null : mb_substr((string)NuSecurity::demoMask($cols[$i] ?? '', $v), 0, 300);
				$rows[] = $row;
			}
			$st->closeCursor();
			return ['columns' => $cols, 'rows' => $rows, 'more' => $more];
		});
	}
}
