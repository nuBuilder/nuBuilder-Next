<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Recycle bin for records (0.8.1325): a form can keep its deleted records for n days (sfo_json nunext.recycle {days}, design mode →
 * Data). The delete stays a real DELETE in the application's table – nuBuilder Forte keeps working on the same data – and a copy of the
 * row and its sub-form rows goes into zzzzsys_nunext_recycle (JSON) in the same transaction. Restore puts the rows back with their keys
 * (only while the key is free; columns that no longer exist are left out, new columns get their defaults).
 * Who: the delete right of the form restores its records; the admin right "recyclebin" sees (view) / restores and empties (change) all.
 * Deletes made by nuBuilder Forte itself, by SQL or by procedures are not caught.
 */
final class NuRecycle
{
	public const TABLE = 'zzzzsys_nunext_recycle';
	public const DEFAULT_DAYS = 30;
	public const MAX_DAYS = 3650;
	public const MAX_BYTES = 8 * 1024 * 1024;               // a bigger copy is not kept (the delete still happens; logged)

	// ----------------------------------------------------------------- setting of a form

	/** Days a form keeps its deleted records (0 = off). */
	public static function days(NuFormMeta $meta): int
	{
		$r = $meta->settings()['recycle'] ?? null;
		return is_array($r) ? max(0, min(self::MAX_DAYS, (int)($r['days'] ?? 0))) : 0;
	}

	/** The design API's form.save field "recycle_days" → the key of sfo_json nunext (null removes it). */
	public static function keysIn(array $fields): array
	{
		if (!array_key_exists('recycle_days', $fields)) return [];
		$d = max(0, min(self::MAX_DAYS, (int)$fields['recycle_days']));
		return ['recycle' => $d > 0 ? ['days' => $d] : null];
	}

	// ----------------------------------------------------------------- rights

	public static function admin(string $level = 'view'): bool
	{
		$u = NuAuth::user();
		return !empty($u['globeadmin']) || NuAdmin::can('recyclebin', $level);
	}

	/** May the user restore / see the deleted records of this form? */
	public static function canForm(string $formId): bool
	{
		return self::admin('change') || NuAcl::can($formId, 'delete');
	}

	/** Forms with entries the user may see (null = all). */
	public static function visibleForms(): ?array
	{
		if (self::admin()) return null;
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return [];
		$out = [];
		foreach ($db->all('SELECT DISTINCT form_id FROM ' . self::TABLE) as $r) if (NuAcl::can((string)$r['form_id'], 'delete')) $out[] = (string)$r['form_id'];
		return $out;
	}

	public static function canSeeAny(): bool
	{
		$f = self::visibleForms();
		return $f === null || $f !== [];
	}

	// ----------------------------------------------------------------- keeping a deleted record

	/**
	 * Called inside the delete transaction of NuRecord::delete, before the rows are deleted. $subs = [[table, fk, pk, rows]].
	 * Returns the entry's id, or null (off / too big).
	 */
	public static function keep(NuFormMeta $meta, string $recordId, array $row, array $subs): ?string
	{
		$days = self::days($meta);
		if ($days <= 0 || NuDemo::active()) return null;
		$db = nu_db();                                       // the table: NuSchema::ensure() before the transaction (NuRecord::delete)
		if (!$db->tableExists(self::TABLE)) return null;
		$n = 0;
		$data = ['v' => 1, 'table' => $meta->table(), 'pk' => $meta->primaryKey(), 'row' => self::pack($row), 'subs' => []];
		foreach ($subs as [$t, $fk, $pk, $rows]) {
			if (!$rows) continue;
			$data['subs'][] = ['table' => $t, 'fk' => $fk, 'pk' => $pk, 'rows' => array_map([self::class, 'pack'], $rows)];
			$n += count($rows);
		}
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false || strlen($json) > self::MAX_BYTES) {
			NuLog::write('recycle_too_big', $meta->code(), $recordId, ['bytes' => $json === false ? 0 : strlen($json)]);
			return null;
		}
		$u = NuAuth::user();
		$id = nu_id();
		$fmt = $db->type === 'sqlsrv' ? 'Y-m-d\TH:i:s' : 'Y-m-d H:i:s';
		$db->insert(self::TABLE, ['zzzzsys_nunext_recycle_id' => $id, 'form_id' => $meta->id, 'form_code' => mb_substr($meta->code(), 0, 100),
			'table_name' => mb_substr($meta->table(), 0, 100), 'record_id' => mb_substr($recordId, 0, 100), 'title' => mb_substr(self::title($meta, $row, $recordId), 0, 300),
			'data' => $json, 'sub_rows' => $n, 'deleted_by' => mb_substr((string)($u['id'] ?? ''), 0, 25), 'deleted_by_name' => mb_substr((string)($u['name'] ?? $u['login'] ?? ''), 0, 100),
			'deleted_at' => date($fmt), 'expires_at' => date($fmt, time() + $days * 86400)]);
		return $id;
	}

	/** A short name of the record: the first two list columns that are plain columns of the row, else its key. */
	public static function title(NuFormMeta $meta, array $row, string $recordId): string
	{
		$lower = array_change_key_case($row, CASE_LOWER);
		$parts = [];
		foreach ($meta->browse as $b) {
			$c = strtolower(trim((string)($b['sbr_display'] ?? '')));
			if ($c !== '' && array_key_exists($c, $lower) && trim((string)$lower[$c]) !== '') $parts[] = trim(strip_tags((string)$lower[$c]));
			if (count($parts) === 2) break;
		}
		return $parts ? implode(' · ', $parts) : $recordId;
	}

	/** Values that are not UTF-8 text (binary columns) are kept as base64. */
	private static function pack(array $row): array
	{
		foreach ($row as $k => $v) if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) $row[$k] = ['__b64' => base64_encode($v)];
		return $row;
	}

	private static function unpack(array $row): array
	{
		foreach ($row as $k => $v) if (is_array($v)) $row[$k] = isset($v['__b64']) ? base64_decode((string)$v['__b64']) : json_encode($v);
		return $row;
	}

	// ----------------------------------------------------------------- reading

	public static function get(string $id): ?array
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return null;
		$r = $db->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_recycle_id = ?', [$id]);
		if (!$r) return null;
		$r['parsed'] = json_decode((string)$r['data'], true) ?: [];
		return $r;
	}

	/** Entries of a form the user may see (newest first) – for counts. */
	public static function count(string $formId): int
	{
		$db = nu_db();
		return $db->tableExists(self::TABLE) ? (int)$db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE form_id = ?', [$formId]) : 0;
	}

	// ----------------------------------------------------------------- restoring

	/**
	 * Puts the record and its sub-form rows back. @return array{ok: bool, message: string, url?: string, form?: string, record?: string}
	 */
	public static function restore(string $id): array
	{
		if (NuDemo::active()) return ['ok' => false, 'message' => t('demo_not_available')];
		$e = self::get($id);
		if (!$e) return ['ok' => false, 'message' => t('rb_err_gone')];
		if (!self::canForm((string)$e['form_id'])) return ['ok' => false, 'message' => t('err_forbidden')];
		$d = $e['parsed'];
		$db = nu_db();
		$table = (string)($d['table'] ?? $e['table_name']);
		$pk = (string)($d['pk'] ?? '');
		if ($table === '' || !$db->tableExists($table)) return ['ok' => false, 'message' => t('rb_err_table', $table)];
		$cols = $db->columns($table);
		$pkCol = NuRecord::column($cols, $pk);
		if ($pkCol === null) return ['ok' => false, 'message' => t('rb_err_table', $table)];
		$row = self::unpack((array)($d['row'] ?? []));
		$rid = (string)$e['record_id'];
		if ((int)$db->value('SELECT COUNT(*) FROM ' . NuDB::ident($table) . ' WHERE ' . NuDB::ident($pkCol) . ' = ?', [$rid])) {
			return ['ok' => false, 'message' => t('rb_err_key', $rid)];
		}
		$skipped = 0;
		NuAudit::begin();
		try {
			$db->transaction(function (NuDB $db) use ($table, $cols, $row, $d, $e, $rid, &$skipped) {
				$db->insert($table, self::fit($cols, $row));
				$ctx = ['form_id' => (string)$e['form_id'], 'form_code' => (string)$e['form_code'], 'source' => 'recycle'];
				NuAudit::record('insert', $table, $rid, [], self::fit($cols, $row), $ctx);
				foreach ((array)($d['subs'] ?? []) as $s) {
					$t = (string)($s['table'] ?? '');
					if ($t === '' || !$db->tableExists($t)) { $skipped += count((array)($s['rows'] ?? [])); continue; }
					$sc = $db->columns($t);
					$spk = NuRecord::column($sc, (string)($s['pk'] ?? ''));
					foreach ((array)($s['rows'] ?? []) as $sr) {
						$sr = self::unpack((array)$sr);
						$key = $spk !== null ? (string)($sr[$spk] ?? '') : '';
						if ($spk !== null && $key !== '' && (int)$db->value('SELECT COUNT(*) FROM ' . NuDB::ident($t) . ' WHERE ' . NuDB::ident($spk) . ' = ?', [$key])) { $skipped++; continue; }
						$db->insert($t, self::fit($sc, $sr));
						if ($spk !== null) NuAudit::record('insert', $t, $key, [], self::fit($sc, $sr), $ctx + ['source' => 'subform', 'parent_table' => $table, 'parent_id' => $rid]);
					}
				}
				$db->run('DELETE FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_recycle_id = ?', [(string)$e['zzzzsys_nunext_recycle_id']]);
			});
		} catch (Throwable $x) {
			error_log('nuBuilder Next recycle restore: ' . $x->getMessage());
			$u = NuAuth::user();
			return ['ok' => false, 'message' => t('rb_err_restore') . (!empty($u['globeadmin']) || nu_debug() ? ' ' . $x->getMessage() : '')];
		} finally {
			NuAudit::end();
		}
		NuBrowse::forgetValues();
		NuLog::write('record_restored', (string)$e['form_code'], $rid, ['table' => $table, 'sub_rows' => (int)$e['sub_rows'], 'skipped' => $skipped]);
		return ['ok' => true, 'message' => $skipped ? t('rb_restored_skipped', $skipped) : t('rb_restored'), 'form' => (string)$e['form_id'], 'record' => $rid,
			'url' => nu_url('form', ['f' => (string)$e['form_id'], 'r' => $rid])];
	}

	/** The row with the columns the table still has, in their stored spelling; temporal / numeric blanks become NULL. */
	private static function fit(array $cols, array $row): array
	{
		$out = [];
		foreach ($row as $k => $v) {
			$c = NuRecord::column($cols, (string)$k);
			if ($c === null) continue;
			$col = $cols[$c];
			if (($v === null || (is_string($v) && trim($v) === '')) && (!empty($col['numeric']) || !empty($col['temporal']))) $v = null;
			if (is_string($v) && !empty($col['temporal']) && nu_db()->type === 'sqlsrv') $v = str_replace(' ', 'T', trim($v));
			$out[$c] = $v;
		}
		return $out;
	}

	// ----------------------------------------------------------------- removing

	/** Deletes entries for good: one id, or all of a form ('' = every form the user may change). */
	public static function remove(array $ids): int
	{
		$db = nu_db();
		$n = 0;
		foreach ($ids as $id) {
			$e = self::get((string)$id);
			if (!$e || !self::canForm((string)$e['form_id'])) continue;
			$n += $db->run('DELETE FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_recycle_id = ?', [(string)$id])->rowCount();
		}
		if ($n) NuLog::write('recycle_emptied', self::TABLE, '', ['entries' => $n]);
		return $n;
	}

	/** Housekeeping: entries past their date. */
	public static function purge(): int
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return 0;
		return $db->run('DELETE FROM ' . self::TABLE . ' WHERE expires_at < ?', [date($db->type === 'sqlsrv' ? 'Y-m-d\TH:i:s' : 'Y-m-d H:i:s')])->rowCount();
	}
}
