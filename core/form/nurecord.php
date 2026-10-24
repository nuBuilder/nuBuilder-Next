<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Loading, saving and deleting form records incl. subform rows and BS/AS/BD/AD hooks. */
final class NuRecord
{
	/** Object types whose value is written to the table column with the same name. */
	private const SAVED_TYPES = ['input', 'select', 'textarea', 'calc', 'lookup', 'editor'];

	public static function load(NuFormMeta $meta, string $id): ?array
	{
		if ($meta->table() === '' || $id === '' || $id === '-1') return null;
		$sql = 'SELECT * FROM ' . NuDB::ident($meta->table()) . ' WHERE ' . NuDB::ident($meta->primaryKey()) . ' = ?';
		return nu_db()->one($sql, [$id]);
	}

	/** Subform rows: the subform form's browse SQL (or its table) filtered by the foreign key. */
	public static function subformRows(array $sfObject, string $parentId): array
	{
		$sub = NuFormMeta::load((string)$sfObject['sob_subform_zzzzsys_form_id']);
		if (!$sub || $parentId === '' || $parentId === '-1') return [];
		$fk  = NuDB::ident(trim((string)$sfObject['sob_subform_foreign_key']));
		$sql = trim((string)$sub->form['sfo_browse_sql']);
		$table = trim((string)$sfObject['sob_subform_table']) ?: $sub->table();
		if ($sql === '') $sql = 'SELECT * FROM ' . NuDB::ident($table);
		[$inner, $order] = NuBrowse::splitOrder(NuHash::replace($sql, NuHash::forForm($sub, $parentId), NuHash::SQL));
		$pk = NuDB::ident($sub->primaryKey());
		return nu_db()->all("SELECT * FROM ($inner) nuT WHERE nuT.$fk = ? ORDER BY " . ($order !== '' ? $order . ', ' : '') . $pk, [$parentId]);
	}

	// ================================================================= nuBuilder's <table>_nulog (0.8.349)

	/**
	 * The record log column of nuBuilder (core/nudata.php): <table>_nulog, JSON {"added": {"user", "time"}, "edited": {"user", "time"}}
	 * – user = user id (globeadmin: 'globeadmin'), time = Unix time; only the last edit. Never taken from the browser.
	 */
	public static function nulogColumn(array $columns, string $table): ?string
	{
		return self::column($columns, $table . '_nulog');
	}

	public static function nulogUser(): string
	{
		$u = NuAuth::user();
		return $u ? (string)$u['id'] : 'unknown';
	}

	/** JSON for a new record. */
	public static function nulogAdded(): string
	{
		return (string)json_encode(['added' => ['user' => self::nulogUser(), 'time' => time()]]);
	}

	/** JSON after an edit: keeps "added" (nuBuilder: {"user": "unknown", "time": 0} when the column was empty or no JSON object). */
	public static function nulogEdited(mixed $current): string
	{
		$d = json_decode((string)$current, true);
		if (!is_array($d) || array_is_list($d)) $d = ['added' => ['user' => 'unknown', 'time' => 0]];
		$d['edited'] = ['user' => self::nulogUser(), 'time' => time()];
		return (string)json_encode($d);
	}

	/** "Added …" / "Edited …" of a record for the ⋮ menu (null: the table has no _nulog column or it is empty). */
	public static function nulogInfo(NuFormMeta $meta, string $recordId): ?array
	{
		if ($meta->table() === '' || $meta->primaryKey() === '' || $recordId === '' || $recordId === '-1') return null;
		$db = nu_db();
		$columns = $db->columns($meta->table());
		$col = $columns ? self::nulogColumn($columns, $meta->table()) : null;
		if ($col === null) return null;
		$v = $db->value('SELECT ' . NuDB::ident($col) . ' FROM ' . NuDB::ident($meta->table()) . ' WHERE ' . NuDB::ident($meta->primaryKey()) . ' = ?', [$recordId]);
		$d = json_decode((string)$v, true);
		if (!is_array($d)) return ['column' => $col, 'added' => null, 'edited' => null];
		$one = static function ($e) use ($db): ?array {
			if (!is_array($e) || !isset($e['user'])) return null;
			$user = (string)$e['user'];
			$name = $user;
			if ($user !== '' && $user !== 'unknown' && $user !== 'globeadmin') {
				$r = $db->one('SELECT sus_login_name, sus_name FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$user]);
				if ($r) $name = trim((string)$r['sus_name']) !== '' ? $r['sus_name'] . ' (' . $r['sus_login_name'] . ')' : (string)$r['sus_login_name'];
			}
			$time = (int)($e['time'] ?? 0);
			return ['user' => $user, 'name' => $name, 'time' => $time, 'when' => $time > 0 ? date('d.m.Y H:i', $time) : ''];
		};
		return ['column' => $col, 'added' => $one($d['added'] ?? null), 'edited' => $one($d['edited'] ?? null)];
	}

	// ================================================================= save

	/**
	 * @param array $p payload: record_id, values{}, edited[], subforms{ id: {rows: [{pk, values{}, edited[], deleted}]}}, props{}
	 */
	public static function save(NuFormMeta $meta, array $p): array
	{
		$db       = nu_db();
		$recordId = (string)($p['record_id'] ?? '-1');
		$isNew    = $recordId === '' || $recordId === '-1';
		$values   = is_array($p['values'] ?? null) ? $p['values'] : [];
		$edited   = array_flip(array_map('strval', is_array($p['edited'] ?? null) ? $p['edited'] : []));
		$props    = is_array($p['props'] ?? null) ? $p['props'] : [];
		$posted   = is_array($p['subforms'] ?? null) ? $p['subforms'] : [];

		if ($meta->table() === '' || $meta->primaryKey() === '') return self::fail(t('form_err_no_table'));
		$oldRow = $isNew ? [] : (self::load($meta, $recordId) ?? null);
		if ($oldRow === null || (!$isNew && !self::visible($meta, $recordId))) return self::fail(t('record_not_found'));

		$table   = NuDB::ident($meta->table());
		$pk      = NuDB::ident($meta->primaryKey());
		$columns = $db->columns($meta->table());
		if (!$columns) return self::fail(t('form_err_table_missing', $meta->table()));

		// concurrent edits (0.8.401): fields someone else changed since the form was opened – before Before Save runs
		$checkCc = !$isNew && empty($p['cc_force']) && is_array($p['cc'] ?? null);
		if ($checkCc && ($c = NuConflict::check($meta, $recordId, $p, false))) return ['ok' => false, 'conflict' => $c];

		NuPhp::reset();
		NuPhp::$subforms = self::nuSubformObjects($meta, $posted);
		$hash = NuHash::forForm($meta, $isNew ? '-1' : $recordId, $values, $props);
		self::$accessHash = NuHash::forForm($meta, $isNew ? '-1' : $recordId, [], $props);   // access conditions (0.8.382): as when the form was drawn

		// --------------------------------------------------- validation (sob_all_validate)
		$errors = self::validate($meta, $values, $posted, $isNew ? null : $recordId);
		if ($errors) return ['ok' => false, 'errors' => $errors];

		// --------------------------------------------------- before save
		if (!NuPhp::run($meta->php('BS'), $hash, 'Before Save')) return ['ok' => false] + NuPhp::result();
		$jsBefore = NuPhp::$js;

		$written = [];
		$audit = ['form_id' => $meta->id, 'form_code' => $meta->code(), 'source' => 'form', 'action' => $isNew && !empty($p['clone']) ? 'clone' : null];
		NuAudit::begin();                                         // audit trail (0.8.350): main record and sub-form rows share one change
		try {
		$newId = $db->transaction(function (NuDB $db) use ($meta, $isNew, $recordId, $values, $edited, $columns, $table, $pk, $posted, $oldRow, $audit, &$written, $checkCc, $p) {
			if ($checkCc && ($c = NuConflict::check($meta, $recordId, $p, true))) throw new NuConflictFound($c);   // again, with the rows locked
			$row = [];
			foreach ($meta->objects as $o) {
				$f = (string)$o['sob_all_id'];
				if (!in_array($o['sob_all_type'], self::SAVED_TYPES, true) || ($o['sob_input_type'] ?? '') === 'button') continue;
				if ($isNew && ($o['sob_input_type'] ?? '') === 'nuAutoNumber' && $o['sob_all_type'] === 'input'
					&& ($col = self::column($columns, $f)) !== null && ($n = NuAutoNumber::next((string)$o['zzzzsys_object_id'])) !== null) {
					$row[$col] = $n;                                   // nuBuilder: new records get the next number (nuAutoNumber / nuUpdateCounter)
					continue;
				}
				if (!array_key_exists($f, $values) || $f === $meta->primaryKey()) continue;
				if (self::protectedField($o)) continue;          // Security check: read-only / hidden objects are not taken from the browser
				$col = self::column($columns, $f);
				if ($col === null) continue;
				if (!$isNew && !isset($edited[$f])) continue;
				if ($isNew && !isset($edited[$f]) && self::untouchedEmpty($o, $values[$f])) continue;   // as nuBuilder: not written – column default / NULL (0.8.364)
				$v = self::dbValue($values[$f], $columns[$col]);
				if ($isNew && $v === null) continue;                 // empty date/number on insert: keep the column default
				$row[$col] = $v;
			}

			// nuBuilder notes / code snippets: "Updated on" follows every save (MySQL does it for cot_updated_on, MSSQL does not)
			$stamp = ['zzzzsys_note' => 'not_updated_on', 'zzzzsys_code_snippet' => 'cot_updated_on'][strtolower($table)] ?? null;
			if ($stamp !== null && ($c = self::column($columns, $stamp)) !== null) $row[$c] = self::dbValue(date('Y-m-d H:i:s'), $columns[$c]);

			// audit columns (forms created with "audit"): set by the server, never taken from the browser
			if (!empty($meta->settings()['audit'])) {
				$user = NuAuth::user();
				$now  = date('Y-m-d H:i:s');
				$who  = $user['globeadmin'] ? 'globeadmin' : $user['login'];
				$set  = $isNew ? ['created_at' => $now, 'created_by' => $who] : ['updated_at' => $now, 'updated_by' => $who];
				foreach (array_keys(NuDDL::AUDIT) as $a) if (($c = self::column($columns, $a)) !== null) unset($row[$c]);
				foreach ($set as $a => $v) if (($c = self::column($columns, $a)) !== null) $row[$c] = self::dbValue($v, $columns[$c]);
			}

			// nuBuilder's <table>_nulog: added on insert, edited on every save of an existing record (as nuBuilder)
			$nl = self::nulogColumn($columns, $meta->table());
			if ($nl !== null) unset($row[$nl]);
			$written = $row;
			if ($nl !== null) $row[$nl] = $isNew ? self::nulogAdded() : self::nulogEdited($db->value("SELECT $nl FROM $table WHERE $pk = ?", [$recordId]));

			if ($isNew) {
				$pkCol = self::column($columns, $meta->primaryKey());
				if ($pkCol !== null && !$columns[$pkCol]['auto']) $row = [$pkCol => nu_id()] + $row;
				$db->insert($table, $row ?: [$pk => nu_id()]);
				$id = isset($row[$pkCol]) && !$columns[$pkCol]['auto'] ? (string)$row[$pkCol] : $db->lastInsertId();
				NuAudit::record('insert', $meta->table(), (string)$id, [], $written, array_filter($audit));
			} else {
				$id = $recordId;
				if ($row) $db->update($table, $row, $pk, $id);
				NuAudit::record('update', $meta->table(), (string)$id, $oldRow, $written, array_filter($audit));
			}

			foreach ($posted as $sfId => $sf) self::saveSubform($meta, (string)$sfId, is_array($sf) ? $sf : [], $id, $audit);
			return $id;
		});
		} catch (NuConflictFound $cf) {
			return ['ok' => false, 'conflict' => $cf->conflict];
		} finally {
			NuAudit::end();
		}

		// --------------------------------------------------- after save
		NuPhp::$js = $jsBefore;
		$hash['RECORD_ID'] = $newId;
		if (nu_cfg('nuConfigLogRecordChanges') && !(NuAudit::on() && NuAudit::audited($meta->table()))) {   // the audit trail replaces these log entries
			$changes = NuLog::diff($oldRow, $written);
			$subs = array_filter(array_map(static fn($sf) => count($sf['rows'] ?? []), $posted));
			if ($changes || $isNew || $subs) {
				NuLog::write($isNew ? 'record_inserted' : 'record_updated', $meta->code(), (string)$newId,
					array_filter(['table' => $meta->table(), 'fields' => $changes, 'subform_rows' => $subs ?: null]));
			}
		}
		$hash[$meta->primaryKey()] = $newId;
		NuPhp::$openRecord = null;
		NuPhp::run($meta->php('AS'), $hash, 'After Save');
		$open = NuPhp::$openRecord !== null && NuPhp::$openRecord !== (string)$newId ? ['open_id' => NuPhp::$openRecord] : [];   // nuReturnNewRecord()

		return ['ok' => true, 'record_id' => $newId] + $open + NuPhp::result();
	}

	/**
	 * A field of a NEW record the user did not touch and that is empty (an unticked checkbox counts as empty): nuBuilder sends only
	 * edited fields (core/nudata.php – a field with a start value is marked edited), so such a column keeps its default / NULL (0.8.364).
	 */
	private static function untouchedEmpty(array $o, mixed $v): bool
	{
		$v = is_array($v) ? '' : trim((string)$v);
		return $v === '' || ($v === '0' && ($o['sob_input_type'] ?? '') === 'checkbox');
	}

	private static function saveSubform(NuFormMeta $meta, string $sfId, array $sf, string $parentId, array $audit = []): void
	{
		$o = $meta->byId[$sfId] ?? null;
		if (!$o || $o['sob_all_type'] !== 'subform') return;
		$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
		if (!$sub) return;

		$db      = nu_db();
		$tName   = trim((string)$o['sob_subform_table']) ?: $sub->table();
		$table   = NuDB::ident($tName);
		$columns = $db->columns($tName);
		$pkCol   = self::column($columns, $sub->primaryKey());
		$fkCol   = self::column($columns, trim((string)$o['sob_subform_foreign_key']));
		if ($pkCol === null || $fkCol === null) throw new RuntimeException("Subform $sfId: primary or foreign key column missing in $tName");
		$canAdd    = (string)$o['sob_subform_add'] !== '0';
		$canDelete = (string)$o['sob_subform_delete'] !== '0';
		$nl        = self::nulogColumn($columns, $tName);                    // nuBuilder's <table>_nulog of the sub-form table
		$ctx       = ['form_id' => $meta->id, 'form_code' => $meta->code(), 'source' => 'subform', 'parent_table' => $meta->table(), 'parent_id' => $parentId];
		$auditOn   = NuAudit::on() && NuAudit::audited($tName);
		$old       = static fn(string $id) => $auditOn ? ($db->one("SELECT * FROM $table WHERE $pkCol = ? AND $fkCol = ?", [$id, $parentId]) ?? []) : [];

		foreach (is_array($sf['rows'] ?? null) ? $sf['rows'] : [] as $r) {
			if (!is_array($r)) continue;
			$rowPk   = trim((string)($r['pk'] ?? ''));
			$deleted = !empty($r['deleted']);
			$vals    = is_array($r['values'] ?? null) ? $r['values'] : [];
			$ed      = array_flip(array_map('strval', is_array($r['edited'] ?? null) ? $r['edited'] : []));

			if ($deleted) {
				if ($rowPk !== '' && $canDelete) {
					$before = $old($rowPk);
					if ($db->run("DELETE FROM $table WHERE $pkCol = ? AND $fkCol = ?", [$rowPk, $parentId])->rowCount() && $before) NuAudit::record('delete', $tName, $rowPk, $before, [], $ctx);
				}
				continue;
			}

			$data = [];
			foreach ($sub->objects as $so) {
				$f = (string)$so['sob_all_id'];
				if (!in_array($so['sob_all_type'], self::SAVED_TYPES, true) || ($so['sob_input_type'] ?? '') === 'button') continue;
				if (!array_key_exists($f, $vals)) continue;
				if (self::protectedField($so)) continue;
				$col = self::column($columns, $f);
				if ($col === null || $col === $pkCol || $col === $fkCol || $col === $nl) continue;
				if ($rowPk !== '' && !isset($ed[$f])) continue;
				if ($rowPk === '' && !isset($ed[$f]) && self::untouchedEmpty($so, $vals[$f])) continue;   // new row: as the main form (0.8.364)
				$data[$col] = self::dbValue($vals[$f], $columns[$col]);
			}

			if ($rowPk === '') {
				if (!$canAdd || !array_filter($data, static fn($v) => $v !== null && $v !== '' && $v !== '0')) continue;   // blank new row
				foreach ($sub->objects as $so) {                       // nuAutoNumber columns of the subform
					if ($so['sob_all_type'] === 'input' && ($so['sob_input_type'] ?? '') === 'nuAutoNumber' && ($c = self::column($columns, (string)$so['sob_all_id'])) !== null
						&& $c !== $pkCol && $c !== $fkCol && ($n = NuAutoNumber::next((string)$so['zzzzsys_object_id'])) !== null) $data[$c] = $n;
				}
				$data[$fkCol] = $parentId;
				if ($nl !== null) $data[$nl] = self::nulogAdded();
				if (!$columns[$pkCol]['auto']) $data = [$pkCol => nu_id()] + $data;
				$db->insert($table, $data);
				if ($auditOn) NuAudit::record('insert', $tName, (string)($columns[$pkCol]['auto'] ? $db->lastInsertId() : $data[$pkCol]), [], $data, $ctx);
			} elseif ($data) {
				$before = $old($rowPk);
				if ($nl !== null) $data[$nl] = self::nulogEdited($db->value("SELECT $nl FROM $table WHERE $pkCol = ? AND $fkCol = ?", [$rowPk, $parentId]));
				$set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($data)));
				$db->run("UPDATE $table SET $set WHERE $pkCol = ? AND $fkCol = ?", [...array_values($data), $rowPk, $parentId]);
				if ($before) NuAudit::record('update', $tName, $rowPk, $before, $data, $ctx);
			}
		}
	}

	// ================================================================= delete

	public static function delete(NuFormMeta $meta, string $recordId, array $props = [], array $in = []): array
	{
		$row = self::load($meta, $recordId);
		if (!$row || !self::visible($meta, $recordId)) return self::fail(t('record_not_found'));
		if ($c = NuConflict::checkDelete($meta, $recordId, $in)) return ['ok' => false, 'conflict' => $c];   // changed since it was opened (0.8.401)

		NuPhp::reset();
		$hash = NuHash::forForm($meta, $recordId, $row, $props);
		if (!NuPhp::run($meta->php('BD'), $hash, 'Before Delete')) return ['ok' => false] + NuPhp::result();

		NuAudit::begin();
		try {
		nu_db()->transaction(function (NuDB $db) use ($meta, $recordId, $row) {
			$ctx = ['form_id' => $meta->id, 'form_code' => $meta->code(), 'source' => 'form'];
			foreach ($meta->objects as $o) {
				if ($o['sob_all_type'] !== 'subform') continue;
				$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
				$t   = trim((string)$o['sob_subform_table']) ?: ($sub?->table() ?? '');
				$fk  = trim((string)$o['sob_subform_foreign_key']);
				if ($t !== '' && $fk !== '' && self::column($db->columns($t), $fk) !== null) {
					$spk = $sub ? self::column($db->columns($t), $sub->primaryKey()) : null;
					if ($spk !== null && NuAudit::on() && NuAudit::audited($t)) {          // audit trail: the sub-form rows go with the record
						foreach ($db->all('SELECT * FROM ' . NuDB::ident($t) . ' WHERE ' . NuDB::ident($fk) . ' = ?', [$recordId]) as $sr) {
							NuAudit::record('delete', $t, (string)$sr[$spk], $sr, [], $ctx + ['source' => 'subform', 'parent_table' => $meta->table(), 'parent_id' => $recordId]);
						}
					}
					$db->run('DELETE FROM ' . NuDB::ident($t) . ' WHERE ' . NuDB::ident($fk) . ' = ?', [$recordId]);
				}
			}
			$db->delete(NuDB::ident($meta->table()), NuDB::ident($meta->primaryKey()), $recordId);
			NuAudit::record('delete', $meta->table(), $recordId, $row, [], $ctx);
		});
		} finally {
			NuAudit::end();
		}

		if (nu_cfg('nuConfigLogRecordChanges') && !(NuAudit::on() && NuAudit::audited($meta->table()))) {
			NuLog::write('record_deleted', $meta->code(), $recordId, ['table' => $meta->table(), 'fields' => NuLog::diff([], $row)]);
		}
		NuPhp::run($meta->php('AD'), $hash, 'After Delete');
		return ['ok' => true] + NuPhp::result();
	}

	// ================================================================= helpers

	/** Column name as stored in the table for a (case-insensitive) field id, or null. */
	public static function column(array $columns, string $field): ?string
	{
		if ($field === '') return null;
		if (isset($columns[$field])) return $field;
		foreach (array_keys($columns) as $c) if (strcasecmp($c, $field) === 0) return $c;
		return null;
	}

	private static function dbValue(mixed $v, array $col): mixed
	{
		if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
		$v = $v === null ? null : (string)$v;
		if (($v === null || trim($v) === '') && ($col['numeric'] || $col['temporal'])) return null;
		if ($v !== null && $col['temporal'] && nu_db()->type === 'sqlsrv') $v = str_replace(' ', 'T', trim($v));
		return $v;
	}

	private static function fail(string $msg): array
	{
		return ['ok' => false, 'errors' => [['message' => $msg, 'field' => '']]];
	}

	/** sob_all_validate: 1 = no blanks, 2 = no duplicates, 3 = both. */
	private static function validate(NuFormMeta $meta, array $values, array $posted, ?string $recordId): array
	{
		$errors = [];
		$db     = nu_db();
		$columns = $db->columns($meta->table());
		foreach ($meta->objects as $o) {
			$v = (string)$o['sob_all_validate'];
			if (!in_array($v, ['1', '2', '3'], true) || !in_array($o['sob_all_type'], self::SAVED_TYPES, true)) continue;
			if (self::hiddenFor($o)) continue;
			$f = (string)$o['sob_all_id'];
			$label = self::label($o);
			$val = $values[$f] ?? '';
			$val = is_array($val) ? implode(',', $val) : trim((string)$val);
			if (($v === '1' || $v === '3') && $val === '') {
				$errors[] = ['message' => t('form_err_blank', $label), 'field' => $f];
			}
			if (($v === '2' || $v === '3') && $val !== '' && ($col = self::column($columns, $f)) !== null) {
				$sql = 'SELECT COUNT(*) FROM ' . NuDB::ident($meta->table()) . " WHERE $col = ?" . ($recordId !== null ? ' AND ' . NuDB::ident($meta->primaryKey()) . ' <> ?' : '');
				if ((int)$db->value($sql, $recordId !== null ? [$val, $recordId] : [$val])) $errors[] = ['message' => t('form_err_duplicate', $label), 'field' => $f];
			}
		}

		// text masks that must be complete
		foreach ($meta->objects as $o) {
			$fmt = self::maskOf($o);
			if ($fmt === '' || self::hiddenFor($o)) continue;
			$val = $values[(string)$o['sob_all_id']] ?? '';
			if (is_string($val) && !NuFormat::maskComplete(trim($val), $fmt)) $errors[] = ['message' => t('fmt_err_incomplete', self::label($o)), 'field' => (string)$o['sob_all_id']];
		}

		foreach ($posted as $sfId => $sf) {
			$o   = $meta->byId[(string)$sfId] ?? null;
			$sub = $o ? NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']) : null;
			if (!$sub || !is_array($sf)) continue;
			foreach ($sub->objects as $so) {
				if (($fmt = self::maskOf($so)) !== '' && !self::hiddenFor($so)) {
					foreach ($sf['rows'] ?? [] as $i => $r) {
						if (!empty($r['deleted']) || (($r['pk'] ?? '') === '' && empty($r['edited']))) continue;
						$val = trim((string)($r['values'][(string)$so['sob_all_id']] ?? ''));
						if (!NuFormat::maskComplete($val, $fmt)) $errors[] = ['message' => t('fmt_err_incomplete_row', self::label($so), $i + 1), 'field' => $sfId . sprintf('%03d', $i) . $so['sob_all_id']];
					}
				}
				$v = (string)$so['sob_all_validate'];
				if (!in_array($v, ['1', '2', '3'], true) || self::hiddenFor($so)) continue;
				$f = (string)$so['sob_all_id'];
				$seen = [];
				foreach ($sf['rows'] ?? [] as $i => $r) {
					if (!empty($r['deleted']) || (($r['pk'] ?? '') === '' && empty($r['edited']))) continue;   // ignore the blank add-row
					$val = trim((string)($r['values'][$f] ?? ''));
					if (($v === '1' || $v === '3') && $val === '') {
						$errors[] = ['message' => t('form_err_blank_row', self::label($so), $i + 1), 'field' => $sfId . sprintf('%03d', $i) . $f];
					}
					if (($v === '2' || $v === '3') && $val !== '') {
						if (isset($seen[$val])) $errors[] = ['message' => t('form_err_duplicate_row', self::label($so), $i + 1), 'field' => $sfId . sprintf('%03d', $i) . $f];
						$seen[$val] = true;
					}
				}
			}
		}
		return $errors;
	}

	/** Text mask of an input ('' when none). */
	private static function maskOf(array $o): string
	{
		$f = trim((string)($o['sob_input_format'] ?? ''));
		return $o['sob_all_type'] === 'input' && str_starts_with($f, 'T|') && in_array((string)$o['sob_input_type'], ['text', 'tel', 'search', ''], true) ? $f : '';
	}

	/**
	 * Admin tools → Security check, "Do not save read-only and hidden fields" (off by default – nuBuilder saves what the browser
	 * sends, and JavaScript that fills such fields relies on it): sob_all_access 1 (read only), 2 (hidden), 3 (hidden for users),
	 * 4 (hidden for users, read only for the globeadmin).
	 */
	public static function protectedField(array $o): bool
	{
		static $on = null;
		$on ??= (bool)NuSecurity::get('protect_fields');
		$a = self::accessOf($o);
		$closed = $a === '1' || $a === '2' || $a === '4' || ($a === '3' && !NuAuth::user()['globeadmin']);
		// an access condition (access 9) is enforced always: what it hides or makes read only is never taken from the browser (0.8.382)
		if ((string)$o['sob_all_access'] === '9') return $closed;
		return $on && $closed;
	}

	/**
	 * nuBuilder's access condition (0.8.382, nuGetObjectAccess() in nuBuilder's core/nuform.php): an object with access 9 runs
	 * sob_all_access_condition (SQL with the form's hash variables); the first value of the single row is its access – 0 editable,
	 * 1 read only, 2 hidden, 3 hidden for users, 4 hidden for users + read only for the globeadmin. No row or several rows = 0, as
	 * nuBuilder. An error hides the object (nuBuilder would show it) – a broken condition must not open a protected field.
	 * $accessHash: the hash of the form being drawn or saved (set by NuRender / save); one query per object and record.
	 */
	public static array $accessHash = [];
	private static array $accessCache = [];

	public static function accessOf(array $o): string
	{
		$a = (string)($o['sob_all_access'] ?? '0');
		if ($a !== '9') return $a;
		$cond = trim((string)($o['sob_all_access_condition'] ?? ''));
		if ($cond === '') return '0';
		$hash = self::$accessHash ?: NuHash::plain();
		$key = (string)($o['zzzzsys_object_id'] ?? '') . "\x1f" . (string)($hash['RECORD_ID'] ?? '') . "\x1f" . md5($cond);
		if (isset(self::$accessCache[$key])) return self::$accessCache[$key];
		try {
			$sql = NuHash::replace($cond, $hash, NuHash::SQL);
			$rows = nu_db()->readOnly(static fn() => nu_db()->all($sql));
			$v = count($rows) === 1 ? (string)(array_values($rows[0])[0] ?? '0') : '0';
			$v = in_array($v, ['0', '1', '2', '3', '4'], true) ? $v : '0';
		} catch (Throwable $e) {
			error_log('nuBuilder Next access condition of ' . ($o['sob_all_id'] ?? '?') . ': ' . $e->getMessage());
			$v = '2';
		}
		return self::$accessCache[$key] = $v;
	}

	/**
	 * Admin tools → Security check, "Records only through the form's list": the record must be found through the form's browse SQL
	 * (with the user's hash variables) – a user cannot open, save or delete a record his list does not show. Off by default
	 * (nuBuilder has no such check). Forms without browse SQL, and browse SQL that fails here (e.g. it needs a temporary table of
	 * Before Browse), are not checked.
	 */
	public static function visible(NuFormMeta $meta, string $recordId): bool
	{
		if (!NuSecurity::get('record_check') || $recordId === '' || $recordId === '-1') return true;
		if (NuAuth::user()['globeadmin'] && empty(NuAuth::user()['demo'])) return true;
		if (trim((string)$meta->form['sfo_browse_sql']) === '' || $meta->primaryKey() === '') return true;
		try {
			[$inner] = NuBrowse::build(NuBrowse::innerSql($meta, NuHash::forForm($meta, $recordId, [], [])), $meta->primaryKey(), []);   // as the list (0.8.382)
			return (bool)nu_db()->value('SELECT COUNT(*) FROM (' . $inner . ') nuT WHERE nuT.nu_pk = ?', [$recordId]);
		} catch (Throwable $e) {
			error_log('nuBuilder Next record check ' . $meta->code() . ': ' . $e->getMessage());
			return true;
		}
	}

	public static function hiddenFor(array $o): bool
	{
		$a = self::accessOf($o);
		return $a === '2' || (in_array($a, ['3', '4'], true) && !NuAuth::user()['globeadmin']);
	}

	private static function label(array $o): string
	{
		$l = trim(strip_tags((string)$o['sob_all_label']));
		return $l !== '' ? $l : (string)$o['sob_all_id'];
	}

	/** Posted subforms in nuBuilder's nuSubformObject() shape, for BS/AS hooks. */
	private static function nuSubformObjects(NuFormMeta $meta, array $posted): array
	{
		$out = [];
		foreach ($posted as $sfId => $sf) {
			$o   = $meta->byId[(string)$sfId] ?? null;
			$sub = $o ? NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']) : null;
			if (!$sub || !is_array($sf)) continue;
			$fields = ['ID'];
			foreach ($sub->objects as $so) if (in_array($so['sob_all_type'], self::SAVED_TYPES, true)) $fields[] = (string)$so['sob_all_id'];
			$fields[] = 'nuDelete';
			$rows = $deleted = $editedRows = [];
			foreach ($sf['rows'] ?? [] as $r) {
				$row = [(string)($r['pk'] ?? '')];
				$ed  = [0];
				$edited = array_flip((array)($r['edited'] ?? []));
				foreach (array_slice($fields, 1, -1) as $f) { $row[] = $r['values'][$f] ?? ''; $ed[] = isset($edited[$f]) ? 1 : 0; }
				$row[] = !empty($r['deleted']) ? 1 : 0;
				$rows[] = $row;
				$deleted[] = !empty($r['deleted']) ? 1 : 0;
				$editedRows[] = $ed;
			}
			$out[(string)$sfId] = [
				'id' => (string)$sfId, 'foreign_key' => (string)$o['sob_subform_foreign_key'], 'primary_key' => $sub->primaryKey(),
				'table' => trim((string)$o['sob_subform_table']) ?: $sub->table(), 'fields' => $fields, 'rows' => $rows,
				'deleted' => $deleted, 'edited' => $editedRows,
			];
		}
		return $out;
	}
}

/** nuBuilder's nuAutoNumber input type: the next value of zzzzsys_object.sob_input_count ("1000" → "1001", "INV7" → "INV8"). */
final class NuAutoNumber
{
	/** Takes the next number (compare-and-set, safe against parallel saves); null when it could not be taken. */
	public static function next(string $objectId): ?string
	{
		$db = nu_db();
		for ($i = 0; $i < 20; $i++) {
			$cur = $db->one('SELECT sob_input_count FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [$objectId]);
			if (!$cur) return null;
			$old    = (string)($cur['sob_input_count'] ?? '');
			$prefix = (string)preg_replace('/[0-9]+/', '', $old);
			$num    = (string)preg_replace('/[^0-9]/', '', $old);
			$new    = $prefix . ((int)($num === '' ? '0' : $num) + 1);
			$st = $old === ''
				? $db->run("UPDATE zzzzsys_object SET sob_input_count = ? WHERE zzzzsys_object_id = ? AND (sob_input_count IS NULL OR sob_input_count = '')", [$new, $objectId])
				: $db->run('UPDATE zzzzsys_object SET sob_input_count = ? WHERE zzzzsys_object_id = ? AND sob_input_count = ?', [$new, $objectId, $old]);
			if ($st->rowCount() === 1) return $new;
		}
		return null;
	}
}

/** A conflict found inside the save transaction (rolls it back). */
final class NuConflictFound extends RuntimeException
{
	public function __construct(public readonly array $conflict)
	{
		parent::__construct('conflict');
	}
}
