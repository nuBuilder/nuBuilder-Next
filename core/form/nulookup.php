<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Lookup objects: the stored value is the lookup form's primary key; the user sees sob_lookup_code and
 * sob_lookup_description. Records come from the lookup form's browse SQL (or sob_lookup_table).
 */
final class NuLookup
{
	public static function form(array $o): ?NuFormMeta
	{
		return NuFormMeta::load((string)$o['sob_lookup_zzzzsys_form_id']);
	}

	/**
	 * [inner sql, pk column]. $byId (0.8.957): nuBuilder Forte reads the chosen record's code and description from the FROM part of the
	 * browse SQL only (nuGetLookupValues: "SELECT pk, code, description <from> WHERE pk = ?") – a WHERE that filters the list (e.g. with
	 * hash variables of a search field) must not hide the stored record.
	 */
	private static function source(array $o, array $hash, bool $byId = false): ?array
	{
		$lf = self::form($o);
		if (!$lf) return null;
		$sql = trim((string)$lf->form['sfo_browse_sql']);
		if ($sql === '') {
			$t = trim((string)$o['sob_lookup_table']) ?: $lf->table();
			if ($t === '') return null;
			$sql = 'SELECT * FROM ' . NuDB::ident($t);
		}
		$h = NuHash::forForm($lf, '', [], $hash) + $hash;
		$h['TABLE_ID'] = self::beforeBrowse($lf, $h);
		[$inner] = NuBrowse::splitOrder(NuHash::replace($sql, $h, NuHash::SQL));
		if ($byId && ($p = NuBrowse::splitSelect($inner)) && $p['where'] !== '' && $p['group'] === '' && $p['having'] === '') $inner = 'SELECT ' . $p['fields'] . ' ' . $p['from'];
		return [$inner, NuDB::ident($lf->primaryKey())];
	}

	/** @var array<string, string> lookup form id → its #TABLE_ID# of this request */
	private static array $bb = [];

	/**
	 * nuBuilder Forte runs the lookup form's Before Browse before it reads code and description (nuGetLookupValues → nuBeforeBrowse) –
	 * forms whose browse SQL reads a table the BB code builds (#TABLE_ID#, e.g. Forte's own File form) need it (0.8.956). Here: once per
	 * lookup form and request, with a #TABLE_ID# of its own, in its own NuPhp state; the table is dropped at the end of the request.
	 */
	private static function beforeBrowse(NuFormMeta $lf, array $h): string
	{
		if (isset(self::$bb[$lf->id])) return self::$bb[$lf->id];
		$tid = '___nu1' . substr(hash('sha256', NuHash::tableId() . '|' . $lf->id), 0, 15);
		self::$bb[$lf->id] = $tid;
		$code = (string)$lf->php('BB');
		if (trim($code) === '') return $tid;
		$h['TABLE_ID'] = $tid;
		$r = NuPhp::isolated(static fn() => NuPhp::run($code, $h, 'Before Browse (lookup)'));
		if (!$r['result']) error_log('nuBuilder Next lookup Before Browse ' . $lf->id . ': ' . json_encode($r['errors']));
		register_shutdown_function(static function () use ($tid): void {
			try { nu_db()->run('DROP TABLE IF EXISTS ' . $tid); } catch (Throwable) { /* gone already */ }
		});
		return $tid;
	}

	private static function expr(string $col): string
	{
		$col = trim($col);
		if ($col === '') return "''";
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col) ? "nuT.$col" : $col;
	}

	private static function plain(string $col): string
	{
		return trim($col) === '' ? "''" : trim($col);
	}

	/** Key of a record read by its id: the object, the list SQL as it runs (hash variables filled in) and the id. */
	private static function memoKey(array $o, string $inner, string $pk): string
	{
		return 'lookup:' . (string)($o['zzzzsys_object_id'] ?? $o['sob_all_id'] ?? '') . '|' . hash('sha256', $inner . '|' . (string)$o['sob_lookup_code'] . '|' . (string)$o['sob_lookup_description']) . '|' . $pk;
	}

	/**
	 * Reads the records of several ids in ONE statement and keeps them for byId() (performance review, 0.8.1341): the rows of a
	 * sub-form asked for their lookup one by one – an order with 50 lines ran 50 statements per lookup column. Only what the single
	 * read would find the same way is kept (same list SQL, the id exactly as stored); everything else is read by byId() as before.
	 * Dropped by any write of the request (NuMemo '*').
	 * @param list<string> $pks
	 */
	public static function prime(array $o, array $pks, array $hash): void
	{
		$pks = array_values(array_unique(array_filter(array_map('strval', $pks), static fn($v) => $v !== '')));
		if (count($pks) < 2) return;
		try {
			$src = self::source($o, $hash, true);
			if (!$src) return;
			[$inner, $pkCol] = $src;
			$pks = array_values(array_filter($pks, static fn($pk) => !NuMemo::has(self::memoKey($o, $inner, $pk))));
			foreach (array_chunk($pks, 500) as $part) {
				if (count($part) < 2) continue;
				$rows = nu_db()->all('SELECT nuT.*, ' . self::expr((string)$o['sob_lookup_code']) . ' AS nu_code, ' . self::expr((string)$o['sob_lookup_description'])
					. " AS nu_description FROM ($inner) nuT WHERE nuT.$pkCol IN (" . implode(', ', array_fill(0, count($part), '?')) . ')', $part);
				$want = array_flip($part);
				foreach ($rows as $row) {
					$pk = (string)($row[$pkCol] ?? '');
					if (!isset($want[$pk])) continue;                       // found by the database's own comparison (case, numbers): left to the single read
					unset($want[$pk]);                                      // the first row of an id counts, as in the single read
					NuMemo::put(self::memoKey($o, $inner, $pk), ['*'], self::shape($row, $pkCol));
				}
			}
		} catch (Throwable) {
			/* the list cannot be read this way: byId() reads row by row and reports */
		}
	}

	/** @return array{pk:string,code:string,description:string,row:array}|null */
	public static function byId(array $o, string $pk, array $hash): ?array
	{
		$src = self::source($o, $hash, true);
		if (!$src || $pk === '') return null;
		[$inner, $pkCol] = $src;
		$key = self::memoKey($o, $inner, $pk);
		if (NuMemo::has($key)) return NuMemo::get($key, ['*'], static fn() => null);
		try {
			$row = nu_db()->one("SELECT nuT.*, " . self::expr((string)$o['sob_lookup_code']) . " AS nu_code, " . self::expr((string)$o['sob_lookup_description'])
				. " AS nu_description FROM ($inner) nuT WHERE nuT.$pkCol = ?", [$pk]);
		} catch (Throwable $e) {
			// the list's SELECT does not give the primary key (or a column of code / description): read them from its tables,
			// as nuBuilder Forte did before 4.9 – "SELECT key, code, description FROM … WHERE key = ?"
			$row = self::fromTables($o, $inner, $pkCol, "$pkCol = ?", [$pk])[0] ?? null;
			if (!$row) { error_log('nuBuilder Next lookup ' . $o['sob_all_id'] . ': ' . $e->getMessage()); return null; }
		}
		if (!$row) return null;
		$rec = self::shape($row, $pkCol);
		NuMemo::put($key, ['*'], $rec);                             // the same record again in this request (another row, the conflict check): no statement
		return $rec;
	}

	/**
	 * Key, code and description straight from the FROM part of the lookup form's list SQL (no GROUP BY / UNION / WITH); [] when
	 * that cannot be done. $where is put after the list's own WHERE.
	 * @return list<array<string, mixed>>
	 */
	private static function fromTables(array $o, string $inner, string $pkCol, string $where, array $params, int $limit = 1): array
	{
		$p = NuBrowse::splitSelect($inner);
		if (!$p || $p['from'] === '' || $p['group'] !== '' || $p['having'] !== '') return [];
		$sql = "SELECT $pkCol AS $pkCol, " . self::plain((string)$o['sob_lookup_code']) . ' AS nu_code, ' . self::plain((string)$o['sob_lookup_description'])
			. ' AS nu_description ' . $p['from'] . ' WHERE ' . ($p['where'] !== '' ? '(' . preg_replace('/^\s*WHERE\s+/i', '', $p['where']) . ') AND ' : '') . "($where)";
		try {
			return nu_db()->page($sql, $params, $pkCol, $limit, 0);
		} catch (Throwable) {
			return [];
		}
	}

	/** Records whose code equals $code (exact, then prefix) – at most 2, to tell "unique" from "ambiguous". */
	public static function byCode(array $o, string $code, array $hash): array
	{
		$src = self::source($o, $hash);
		if (!$src || trim($code) === '') return [];
		[$inner, $pkCol] = $src;
		$codeExpr = self::expr((string)$o['sob_lookup_code']);
		$sel = "SELECT nuT.*, $codeExpr AS nu_code, " . self::expr((string)$o['sob_lookup_description']) . " AS nu_description FROM ($inner) nuT";
		$db  = nu_db();
		try {
			$rows = $db->page("$sel WHERE $codeExpr = ?", [$code], "nuT.$pkCol", 2, 0);
		} catch (Throwable $e) {                              // the list does not select the key: from its tables (see byId)
			$c = self::plain((string)$o['sob_lookup_code']);
			$rows = self::fromTables($o, $inner, $pkCol, "$c = ?", [$code], 2) ?: self::fromTables($o, $inner, $pkCol, "$c LIKE ? ESCAPE '!'", [NuDB::like($code)], 2);
			return array_map(static fn($r) => self::shape($r, $pkCol), $rows);
		}
		// nuBuilder Forte: no exact code → code OR description contains the text (nuGetAllLookupList)
		$descExpr = self::expr((string)$o['sob_lookup_description']);
		if (!$rows) $rows = $db->page("$sel WHERE $codeExpr LIKE ? ESCAPE '!'" . ($descExpr !== "''" ? " OR $descExpr LIKE ? ESCAPE '!'" : ''), $descExpr !== "''" ? [NuDB::like($code), NuDB::like($code)] : [NuDB::like($code)], "nuT.$pkCol", 2, 0);
		return array_map(static fn($r) => self::shape($r, $pkCol), $rows);
	}

	private static function shape(array $row, string $pkCol): array
	{
		$pk = (string)($row[$pkCol] ?? '');
		$code = (string)($row['nu_code'] ?? '');
		$desc = (string)($row['nu_description'] ?? '');
		unset($row['nu_code'], $row['nu_description']);
		return ['pk' => $pk, 'code' => $code, 'description' => $desc, 'row' => $row];
	}

	/** Runs the lookup's After Browse code (<object id>_AB) with nuLookupRecord() = the chosen row. */
	public static function afterBrowse(array $o, array $record, array $hash): array
	{
		NuPhp::reset();
		NuPhp::$lookupRow = (object)(['ID' => (string)$record['pk']] + (array)$record['row']);   // nuBuilder: the row with its key as ID (0.8.462)
		// through NuFormMeta: in a draft preview the draft's code (0.8.386)
		$code = NuFormMeta::load((string)$o['sob_all_zzzzsys_form_id'])?->objectPhp((string)$o['zzzzsys_object_id'], 'AB');
		NuPhp::run($code, $hash, 'Lookup ' . $o['sob_all_id']);
		return NuPhp::result();
	}
}
