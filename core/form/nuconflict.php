<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Concurrent edits (0.8.401): optimistic check of the edited fields and the "also open" hint (presence).
 *
 * Conflict check: when an edit form is drawn, the browser gets a short signature of every column of the record and of its sub-form
 * rows (sigs()). On save it sends the signatures of the fields it edited (payload cc); the server compares them with the database
 * now – before Before Save and again, with a row lock, inside the save transaction. A field someone else changed meanwhile is a
 * conflict unless both wrote the same value; fields only the other user changed are kept (only edited fields are written).
 * Sub-form rows: edited fields per row, a row deleted by someone else, a row deleted here that was changed meanwhile. Delete: the
 * signature of the whole record. Changes from nuBuilder, SQL or procedures are found too (the database is compared, not a version).
 * Off: $nuConfigEditConflictCheck = false, per form nuNextConflictCheck(false) in Before Edit; the browser then sends no signatures.
 *
 * Presence (D1): zzzzsys_nunext_presence – the edit form pings every 30 s (formapi action presence, passive: does not keep the session
 * alive); others who have the same record open (seen in the last 75 s) are shown above the form. Never blocks.
 * Off: $nuConfigEditPresence = false, per form nuNextPresence(false) in Before Edit; always off in demo mode.
 */
final class NuConflict
{
	public const PRESENCE = 'zzzzsys_nunext_presence';
	public const PRESENCE_SECONDS = 75;

	/** Set by nuNextConflictCheck() / nuNextPresence() in Before Edit (null = not set). */
	public static ?bool $formCheck = null;
	public static ?bool $formPresence = null;

	public static function enabled(): bool
	{
		return nu_cfg('nuConfigEditConflictCheck') !== false;
	}

	public static function presenceEnabled(): bool
	{
		return nu_cfg('nuConfigEditPresence') !== false && !NuDemo::active();
	}

	public static function sig(mixed $v): string
	{
		return $v === null ? 'n' : substr(md5((string)$v), 0, 12);
	}

	/** lower-case column => signature */
	public static function rowSigs(array $row): array
	{
		$out = [];
		foreach ($row as $k => $v) $out[strtolower((string)$k)] = self::sig($v);
		return $out;
	}

	/** Signature of the whole row (delete check). */
	public static function rowHash(array $row): string
	{
		$s = self::rowSigs($row);
		ksort($s);
		return substr(md5(json_encode($s)), 0, 16);
	}

	/** What the edit form needs: ['f' => sigs, 'h' => hash, 'sf' => [subform id => [pk => ['f' => sigs, 'h' => hash]]]]. */
	public static function forRecord(NuFormMeta $meta, string $recordId, array $row): array
	{
		$sigs = self::rowSigs($row);
		foreach ($meta->objects as $o) if (NuFieldAccess::withheld($o) || NuFieldAccess::masked($o)) unset($sigs[strtolower((string)$o['sob_all_id'])]);   // no trace of what the user must not see (0.8.480)
		$out = ['f' => (object)$sigs, 'h' => self::rowHash($row), 'sf' => []];
		foreach ($meta->objects as $o) {
			if ($o['sob_all_type'] !== 'subform') continue;
			[$tName, $pkCol, $fkCol] = self::subTable($o);
			if ($pkCol === null || $fkCol === null) continue;
			$rows = [];
			foreach (nu_db()->all('SELECT * FROM ' . NuDB::ident($tName) . ' WHERE ' . NuDB::ident($fkCol) . ' = ?', [$recordId]) as $r) {
				$rows[(string)$r[$pkCol]] = ['f' => (object)self::rowSigs($r), 'h' => self::rowHash($r)];
			}
			$out['sf'][(string)$o['sob_all_id']] = (object)$rows;
		}
		$out['sf'] = (object)$out['sf'];
		return $out;
	}

	/** [table, primary key column, foreign key column] of a sub-form object (columns as stored, null when missing). */
	private static function subTable(array $o): array
	{
		$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
		if (!$sub) return ['', null, null];
		$tName = trim((string)$o['sob_subform_table']) ?: $sub->table();
		$cols = $tName !== '' ? nu_db()->columns($tName) : [];
		if (!$cols) return [$tName, null, null];
		return [$tName, NuRecord::column($cols, $sub->primaryKey()), NuRecord::column($cols, trim((string)$o['sob_subform_foreign_key']))];
	}

	/** The current row – locked inside a transaction ($lock). */
	private static function current(string $table, string $pkCol, string $id, bool $lock, ?string $fkCol = null, ?string $parent = null): ?array
	{
		$db = nu_db();
		$t = NuDB::ident($table);
		$where = NuDB::ident($pkCol) . ' = ?' . ($fkCol !== null ? ' AND ' . NuDB::ident($fkCol) . ' = ?' : '');
		$params = $fkCol !== null ? [$id, (string)$parent] : [$id];
		if ($db->type === 'sqlsrv') return $db->one("SELECT * FROM $t" . ($lock ? ' WITH (UPDLOCK, ROWLOCK)' : '') . " WHERE $where", $params);
		return $db->one("SELECT * FROM $t WHERE $where" . ($lock && $db->type === 'mysql' ? ' FOR UPDATE' : ''), $params);
	}

	/** Value of a row for a field id (case-insensitive), [found, value]. */
	private static function valueOf(array $row, string $field): array
	{
		if (array_key_exists($field, $row)) return [true, $row[$field]];
		foreach ($row as $k => $v) if (strcasecmp((string)$k, $field) === 0) return [true, $v];
		return [false, null];
	}

	/** Same value as now in the database (the user wrote what the other one wrote)? */
	private static function same(mixed $mine, mixed $db): bool
	{
		if (is_array($mine)) $mine = json_encode($mine, JSON_UNESCAPED_UNICODE);
		$a = trim((string)$mine);
		$b = trim((string)$db);
		if ($a === $b) return true;
		return is_numeric($a) && is_numeric($b) && (float)$a === (float)$b;
	}

	/**
	 * Conflicts of a save. @param array $p the save payload (record_id, values, subforms, cc)
	 * @return array{} | array{gone?: bool, fields: list<array>, by: string, at: string}
	 */
	public static function check(NuFormMeta $meta, string $recordId, array $p, bool $lock): array
	{
		$cc = is_array($p['cc'] ?? null) ? $p['cc'] : null;
		if ($cc === null || !self::enabled()) return [];
		$cols = nu_db()->columns($meta->table());
		$pkCol = NuRecord::column($cols, $meta->primaryKey());
		if ($pkCol === null) return [];
		$cur = self::current($meta->table(), $pkCol, $recordId, $lock);
		if ($cur === null) return self::result([['field' => '', 'gone' => true, 'label' => '']], $meta->table(), $recordId, null, null, true);
		$fields = [];
		$values = is_array($p['values'] ?? null) ? $p['values'] : [];
		foreach (is_array($cc['f'] ?? null) ? $cc['f'] : [] as $f => $sig) {
			$f = (string)$f;
			[$found, $now] = self::valueOf($cur, $f);
			if (!$found || self::sig($now) === (string)$sig) continue;
			if (array_key_exists($f, $values) && self::same($values[$f], $now)) continue;
			$fields[] = ['sf' => '', 'pk' => '', 'field' => $f, 'label' => self::label($meta, $f), 'theirs' => $now === null ? '' : (string)$now, 'sig' => self::sig($now)];
		}
		$who = $fields ? [$meta->table(), $recordId, $cur] : null;

		// sub-form rows
		$posted = is_array($p['subforms'] ?? null) ? $p['subforms'] : [];
		foreach (is_array($cc['sf'] ?? null) ? $cc['sf'] : [] as $sfId => $rowsCc) {
			$o = $meta->byId[(string)$sfId] ?? null;
			if (!$o || $o['sob_all_type'] !== 'subform' || !is_array($rowsCc)) continue;
			[$tName, $spk, $sfk] = self::subTable($o);
			if ($spk === null || $sfk === null) continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
			$byPk = [];
			foreach ((array)($posted[$sfId]['rows'] ?? []) as $i => $r) if (is_array($r) && (string)($r['pk'] ?? '') !== '') $byPk[(string)$r['pk']] = [$i, $r];
			foreach ($rowsCc as $rowPk => $rc) {
				$rowPk = (string)$rowPk;
				if (!is_array($rc) || !isset($byPk[$rowPk])) continue;
				[$i, $r] = $byPk[$rowPk];
				$now = self::current($tName, $spk, $rowPk, $lock, $sfk, $recordId);
				$base = ['sf' => (string)$sfId, 'pk' => $rowPk, 'row' => (int)$i + 1, 'sf_label' => trim(strip_tags((string)$o['sob_all_label'])) ?: (string)$sfId];
				if (!empty($r['deleted'])) {                                   // deleted here: changed by someone else since?
					if ($now !== null && isset($rc['h']) && self::rowHash($now) !== (string)$rc['h']) {
						$fields[] = $base + ['field' => '', 'label' => '', 'deleted_changed' => true, 'h' => self::rowHash($now)];
						$who ??= [$tName, $rowPk, $now];
					}
					continue;
				}
				if ($now === null) {                                          // edited here, deleted by someone else
					if (is_array($rc['f'] ?? null) && $rc['f']) $fields[] = $base + ['field' => '', 'label' => '', 'gone' => true];
					continue;
				}
				$vals = is_array($r['values'] ?? null) ? $r['values'] : [];
				foreach (is_array($rc['f'] ?? null) ? $rc['f'] : [] as $f => $sig) {
					$f = (string)$f;
					[$found, $v] = self::valueOf($now, $f);
					if (!$found || self::sig($v) === (string)$sig) continue;
					if (array_key_exists($f, $vals) && self::same($vals[$f], $v)) continue;
					$fields[] = $base + ['field' => $f, 'label' => $sub ? self::label($sub, $f) : $f, 'theirs' => $v === null ? '' : (string)$v, 'sig' => self::sig($v)];
					$who ??= [$tName, $rowPk, $now];
				}
			}
		}
		if (!$fields) return [];
		return self::result($fields, ...($who ?? [$meta->table(), $recordId, $cur]));
	}

	/** Delete: the record changed since it was opened? @return array{} | array{delete: true, by: string, at: string} */
	public static function checkDelete(NuFormMeta $meta, string $recordId, array $in): array
	{
		$h = $in['cc_h'] ?? null;
		if (!is_string($h) || $h === '' || !empty($in['cc_force']) || !self::enabled()) return [];
		$row = NuRecord::load($meta, $recordId);
		if ($row === null || self::rowHash($row) === $h) return [];
		return self::result([], $meta->table(), $recordId, $row) + ['delete' => true];
	}

	private static function result(array $fields, string $table, string $id, ?array $row, ?string $column = null, bool $gone = false): array
	{
		[$by, $at] = self::who($table, $id, $row, $fields);
		return ['fields' => $fields, 'by' => $by, 'at' => $at] + ($gone ? ['gone' => true] : []);
	}

	/** Who changed it last: the audit trail (per column when known), else nuBuilder's <table>_nulog, else ''. */
	public static function who(string $table, string $id, ?array $row, array $fields = []): array
	{
		$db = nu_db();
		try {
			if (NuAudit::on() && $db->tableExists(NuAudit::TABLE)) {
				$colsWanted = array_values(array_filter(array_map(static fn($f) => (string)($f['field'] ?? ''), $fields)));
				$sql = 'SELECT login, changed_at FROM ' . NuAudit::TABLE . ' WHERE table_name = ? AND record_id = ?';
				$params = [$table, $id];
				if ($colsWanted) {
					$sql .= ' AND column_name IN (' . implode(',', array_fill(0, count($colsWanted), '?')) . ')';
					array_push($params, ...$colsWanted);
				}
				$r = $db->page($sql, $params, 'changed_at DESC', 1, 0)[0] ?? null;
				if ($r) return [(string)$r['login'], date('d.m.Y H:i', (int)strtotime((string)$r['changed_at']))];
			}
		} catch (Throwable) { /* no audit information */ }
		if ($row) {
			foreach ($row as $k => $v) {
				if (strcasecmp((string)$k, $table . '_nulog') !== 0) continue;
				$d = json_decode((string)$v, true);
				$e = is_array($d) ? ($d['edited'] ?? $d['added'] ?? null) : null;
				if (!is_array($e) || !isset($e['user'])) break;
				$user = (string)$e['user'];
				$name = $user;
				if ($user !== '' && $user !== 'unknown' && $user !== 'globeadmin') $name = (string)($db->value('SELECT sus_login_name FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$user]) ?: $user);
				$time = (int)($e['time'] ?? 0);
				return [$name === 'unknown' ? '' : $name, $time > 0 ? date('d.m.Y H:i', $time) : ''];
			}
		}
		return ['', ''];
	}

	private static function label(NuFormMeta $meta, string $field): string
	{
		$o = $meta->byId[$field] ?? null;
		if (!$o) foreach ($meta->objects as $x) if (strcasecmp((string)$x['sob_all_id'], $field) === 0) { $o = $x; break; }
		$l = $o ? trim(strip_tags((string)$o['sob_all_label'])) : '';
		return $l !== '' ? $l : $field;
	}

	// ================================================================= presence

	/**
	 * Notes that the user has the record open (edited: unsaved changes) and returns the others who have it open:
	 * list of [name, login, since, edited]. $leave: the form was closed.
	 */
	public static function ping(string $formId, string $recordId, bool $edited, bool $leave = false): array
	{
		if (!self::presenceEnabled() || $recordId === '' || $recordId === '-1') return [];
		NuSchema::ensure();
		$u = NuAuth::user();
		$uid = $u['globeadmin'] ? 'globeadmin' : (string)$u['id'];
		$db = nu_db();
		$now = date('Y-m-d H:i:s');
		$t = self::PRESENCE;
		$key = substr(md5($uid . '|' . $formId . '|' . $recordId . '|' . session_id()), 0, 25);   // per user, record and session
		if ($leave) { $db->delete($t, 'zzzzsys_nunext_presence_id', $key); return []; }
		$name = $u['globeadmin'] ? 'globeadmin' : (trim((string)($u['display'] ?? '')) ?: (string)$u['login']);
		$vals = ['form_id' => mb_substr($formId, 0, 25), 'record_id' => mb_substr($recordId, 0, 100), 'user_id' => mb_substr($uid, 0, 25),
			'login' => mb_substr($u['globeadmin'] ? 'globeadmin' : (string)$u['login'], 0, 50), 'name' => mb_substr($name, 0, 100), 'edited' => $edited ? '1' : '0', 'seen_at' => $now];
		if ($db->value("SELECT COUNT(*) FROM $t WHERE zzzzsys_nunext_presence_id = ?", [$key])) $db->update($t, $vals, 'zzzzsys_nunext_presence_id', $key);
		else $db->insert($t, ['zzzzsys_nunext_presence_id' => $key, 'opened_at' => $now] + $vals);
		if (random_int(1, 20) === 1) $db->run("DELETE FROM $t WHERE seen_at < ?", [date('Y-m-d H:i:s', time() - 3600)]);   // old rows go now and then
		$rows = $db->all("SELECT user_id, login, name, opened_at, edited FROM $t WHERE form_id = ? AND record_id = ? AND user_id <> ? AND seen_at >= ?",
			[$formId, $recordId, $uid, date('Y-m-d H:i:s', time() - self::PRESENCE_SECONDS)]);
		$out = [];
		foreach ($rows as $r) {
			$k = (string)$r['user_id'];
			if (isset($out[$k])) { $out[$k]['edited'] = $out[$k]['edited'] || (string)$r['edited'] === '1'; continue; }
			$out[$k] = ['name' => (string)$r['name'], 'login' => (string)$r['login'], 'since' => date('H:i', (int)strtotime((string)$r['opened_at'])), 'edited' => (string)$r['edited'] === '1'];
		}
		return array_values($out);
	}
}
