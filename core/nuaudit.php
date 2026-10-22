<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Audit trail (0.8.350): every change nuBuilder Next makes to the tables of forms, one row per changed column, in
 * zzzzsys_nunext_audit – who (user id, login, IP), when, action (insert / update / delete / clone / restore), source (form /
 * subform / import / restore / log = copied from the former log entries), table, record, form, parent record of a sub-form row,
 * column, old and new value. One save shares a change_id. Captured in NuRecord::save / delete (also sub-form rows) and the CSV
 * import; nuBuilder's own zzzzsys_ tables are not audited here (the log has those). nuBuilder itself, procedures and phpMyAdmin are
 * not seen (no database triggers).
 * Settings (row 'audit' in zzzzsys_nunext_setting): on (default: $nuConfigLogRecordChanges), keep_days (0 = for ever), exclude
 * ("table" or "table.column", comma separated). Secret-looking columns (NuSecurity::secretName) are stored as •••; values longer
 * than MAX are cut and marked, they cannot be restored.
 * Pages: ?p=audit (list, admin right "audit" = pseudo form nunextaudit), ?p=audithistory&f=&r= (history of a record, restore).
 */
final class NuAudit
{
	public const TABLE = 'zzzzsys_nunext_audit';
	private const SETTING = 'audit';
	public const ACTIONS = ['insert', 'update', 'delete', 'clone', 'restore'];
	public const MAX = 4000;
	public const CUT = ' …[cut, sha1 ';

	private static ?array $settings = null;
	private static string $changeId = '';

	// ================================================================= settings

	public static function settings(): array
	{
		if (self::$settings !== null) return self::$settings;
		$d = null;
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$d = is_string($v) ? json_decode($v, true) : null;
		} catch (Throwable) {
		}
		$on = is_array($d) && array_key_exists('on', $d) ? (bool)$d['on'] : (bool)nu_cfg('nuConfigLogRecordChanges');
		return self::$settings = ['on' => $on, 'keep_days' => max(0, (int)($d['keep_days'] ?? 0)), 'exclude' => trim((string)($d['exclude'] ?? ''))];
	}

	/** @return array changed keys => [old, new] */
	public static function saveSettings(array $in): array
	{
		$old = self::settings();
		$new = ['on' => !empty($in['on']), 'keep_days' => max(0, min(36500, (int)($in['keep_days'] ?? 0))),
			'exclude' => mb_substr(trim((string)preg_replace('/\s*[,;\n]+\s*/', ', ', (string)($in['exclude'] ?? ''))), 0, 2000)];
		$changed = [];
		foreach ($new as $k => $v) if ($v !== $old[$k]) $changed[$k] = [$old[$k], $v];
		if (!$changed) return [];
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => json_encode($new), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => (string)(NuAuth::user()['login'] ?? '')];
		if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
		else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
		self::$settings = null;
		NuLog::write('audit_settings', self::TABLE, '', ['fields' => $changed]);
		return $changed;
	}

	public static function on(): bool
	{
		return self::settings()['on'];
	}

	/** Is this table (or column) audited? nuBuilder's / nuBuilder Next's own tables never. */
	public static function audited(string $table, string $column = ''): bool
	{
		if ($table === '' || str_starts_with(strtolower($table), 'zzzzsys_')) return false;
		$ex = array_map('strtolower', array_filter(array_map('trim', explode(',', self::settings()['exclude']))));
		if (in_array(strtolower($table), $ex, true)) return false;
		return $column === '' || !in_array(strtolower($table . '.' . $column), $ex, true);
	}

	// ================================================================= pages

	/** Tabs of the log pages (debug results, log, audit trail, sessions) – those the user may open; '' with only one. */
	public static function tabs(string $active): string
	{
		$t = [];
		if (NuAcl::can(NuAcl::FORM_DEBUG)) $t['debug'] = ['bi-bug', t('debug_title')];
		if (NuAcl::can(NuAcl::FORM_LOG)) $t['log'] = ['bi-journal-text', t('nav_log')];
		if (NuAcl::can(NuAcl::FORM_AUDIT)) $t['audit'] = ['bi-clock-history', t('aud_title')];
		if (NuAdmin::can('sessions')) $t['sessions'] = ['bi-people', t('sess_title')];
		if (count($t) < 2) return '';
		$out = '<ul class="nav nav-tabs mb-3">';
		foreach ($t as $k => [$ic, $lb]) {
			$out .= '<li class="nav-item"><a class="nav-link' . ($k === $active ? ' active" aria-current="page' : '') . '" href="' . h(nu_url($k)) . '"><i class="bi ' . $ic . ' me-1"></i>' . h($lb) . '</a></li>';
		}
		return $out . '</ul>';
	}

	// ================================================================= writing

	/** Starts a change (one save: main record + sub-form rows). @return string the change id */
	public static function begin(): string
	{
		// the table is created here, before the caller's transaction (CREATE TABLE would end a MySQL transaction)
		try { if (self::on() && !nu_db()->tableExists(self::TABLE)) NuSchema::create(); } catch (Throwable $e) { error_log('nuBuilder Next audit: ' . $e->getMessage()); }
		return self::$changeId = nu_id();
	}

	public static function end(): void
	{
		self::$changeId = '';
	}

	/** Value as stored in the audit table: null stays null, secrets •••, long values cut (with a hash, so they can be compared). */
	private static function value(string $column, mixed $v): ?string
	{
		if ($v === null) return null;
		if (is_bool($v)) $v = $v ? '1' : '0';
		$v = is_scalar($v) ? (string)$v : (string)json_encode($v, JSON_UNESCAPED_UNICODE);
		if (NuSecurity::secretName($column) || NuSecurity::hashValue($v)) return '•••';
		if (mb_strlen($v) > self::MAX) return mb_substr($v, 0, self::MAX - 60) . self::CUT . sha1($v) . ']';
		return $v;
	}

	/** Same value? (numbers / dates as the database returns them: "5" = "5.00", "2026-01-01" = "2026-01-01 00:00:00") */
	private static function same(mixed $a, mixed $b): bool
	{
		if ($a === null || $b === null) return $a === $b || ((string)$a === '' && (string)$b === '');
		$a = (string)$a; $b = (string)$b;
		if ($a === $b) return true;
		if (is_numeric($a) && is_numeric($b)) return (float)$a === (float)$b;
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $a) && preg_match('/^\d{4}-\d{2}-\d{2}/', $b)) return strtotime(str_replace('T', ' ', $a)) === strtotime(str_replace('T', ' ', $b));
		return false;
	}

	/**
	 * Writes the changes of one row. $old / $new: column => value (insert: $old = [], delete: $new = []; update: only the columns
	 * written in $new, compared with $old). $ctx: source, form_id, form_code, parent_table, parent_id, action (overrides).
	 * @return int rows written
	 */
	public static function record(string $action, string $table, string $recordId, array $old, array $new, array $ctx = []): int
	{
		if (!self::on() || !self::audited($table)) return 0;
		try {
			$db = nu_db();
			if (!$db->tableExists(self::TABLE)) return 0;
			$u = NuAuth::user();
			$base = [
				'change_id' => self::$changeId !== '' ? self::$changeId : nu_id(), 'changed_at' => date('Y-m-d H:i:s'),
				'user_id' => mb_substr((string)($u['id'] ?? ''), 0, 25), 'login' => mb_substr((string)($u['login'] ?? ''), 0, 50), 'ip' => mb_substr(nu_client_ip(), 0, 45),
				'action' => (string)($ctx['action'] ?? $action), 'source' => (string)($ctx['source'] ?? 'form'), 'table_name' => mb_substr($table, 0, 100),
				'record_id' => mb_substr($recordId, 0, 100), 'form_id' => mb_substr((string)($ctx['form_id'] ?? ''), 0, 25), 'form_code' => mb_substr((string)($ctx['form_code'] ?? ''), 0, 100),
				'parent_table' => mb_substr((string)($ctx['parent_table'] ?? ''), 0, 100), 'parent_id' => mb_substr((string)($ctx['parent_id'] ?? ''), 0, 100),
			];
			$cols = $action === 'delete' ? array_keys($old) : array_keys($new);
			$n = 0;
			foreach ($cols as $c) {
				$c = (string)$c;
				if (!self::audited($table, $c) || str_ends_with(strtolower($c), '_nulog') || isset(NuDDL::AUDIT[strtolower($c)])) continue;
				$o = $action === 'insert' ? null : ($old[$c] ?? null);
				$v = $action === 'delete' ? null : ($new[$c] ?? null);
				if ($action === 'update' && self::same($o, $v)) continue;
				if ($action !== 'update' && ($action === 'delete' ? $o : $v) === null) continue;
				$db->insert(self::TABLE, ['zzzzsys_nunext_audit_id' => nu_id(), 'column_name' => mb_substr($c, 0, 100),
					'old_value' => self::value($c, $o), 'new_value' => self::value($c, $v)] + $base);
				$n++;
			}
			if ($n === 0 && $action === 'delete') {                   // a deleted row without values: still one row
				$db->insert(self::TABLE, ['zzzzsys_nunext_audit_id' => nu_id(), 'column_name' => '', 'old_value' => null, 'new_value' => null] + $base);
				$n = 1;
			}
			return $n;
		} catch (Throwable $e) {
			error_log('nuBuilder Next audit: ' . $e->getMessage());   // the audit must never stop a save
			return 0;
		}
	}

	// ================================================================= reading

	/** Changes of a record and its sub-form rows, newest first, grouped by change_id. @return list<array{change_id, at, login, user_id, action, source, rows}> */
	public static function history(string $table, string $recordId, int $limit = 500): array
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return [];
		$rows = $db->page('SELECT * FROM ' . self::TABLE . ' WHERE (table_name = ? AND record_id = ?) OR (parent_table = ? AND parent_id = ?)',
			[$table, $recordId, $table, $recordId], 'changed_at DESC, change_id', $limit, 0);
		$out = [];
		foreach ($rows as $r) {
			$k = (string)$r['change_id'];
			$out[$k] ??= ['change_id' => $k, 'at' => (string)$r['changed_at'], 'login' => (string)$r['login'], 'user_id' => (string)$r['user_id'], 'rows' => []];
			$out[$k]['rows'][] = $r;
		}
		return array_values($out);
	}

	/**
	 * Columns of a form and of its sub-forms that the signed-in user sees there: [table (lower case) => [column (lower case) => object]].
	 * null = everything (globeadmin, or the audit trail's own right – the list shows all columns anyway). Security review 0.8.367:
	 * the history of a record showed hidden / admin-only fields and columns of other forms to whoever could open the form.
	 */
	public static function formColumns(NuFormMeta $meta): ?array
	{
		$u = NuAuth::user();
		if (!$u || (($u['globeadmin'] ?? false) && empty($u['demo'])) || NuAcl::can(NuAcl::FORM_AUDIT)) return null;
		$out = [];
		$add = static function (NuFormMeta $m, string $table) use (&$out): void {
			foreach ($m->objects as $o) if (!NuRecord::hiddenFor($o)) $out[strtolower($table)][strtolower((string)$o['sob_all_id'])] = $o;
		};
		$add($meta, $meta->table());
		foreach ($meta->objects as $o) {
			if ($o['sob_all_type'] !== 'subform' || NuRecord::hiddenFor($o)) continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
			if ($sub) $add($sub, trim((string)$o['sob_subform_table']) ?: $sub->table());
		}
		return $out;
	}

	/** history() without the columns the user does not see on the form (formColumns). */
	public static function historyFor(NuFormMeta $meta, string $recordId): array
	{
		$changes = self::history($meta->table(), $recordId);
		$cols = self::formColumns($meta);
		if ($cols === null) return $changes;
		$out = [];
		foreach ($changes as $c) {
			$c['rows'] = array_values(array_filter($c['rows'], static fn($r) => (string)$r['column_name'] === ''
				|| isset($cols[strtolower((string)$r['table_name'])][strtolower((string)$r['column_name'])])));
			if ($c['rows']) $out[] = $c;
		}
		return $out;
	}

	// ================================================================= restore

	/**
	 * Primary key column of an audited table: the form's own table, or the table of one of its sub-forms (form_id of an audit row is
	 * always the form the change was made in – the main form for sub-form rows). @return ?string
	 */
	public static function keyOf(NuFormMeta $meta, string $table): ?string
	{
		if (strcasecmp($meta->table(), $table) === 0) return $meta->primaryKey() ?: null;
		foreach ($meta->objects as $o) {
			if ($o['sob_all_type'] !== 'subform') continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
			$t = trim((string)$o['sob_subform_table']) ?: ($sub?->table() ?? '');
			if ($sub && strcasecmp($t, $table) === 0) return $sub->primaryKey() ?: null;
		}
		return null;
	}

	/** Can this audit row be restored (action, value not cut / masked)? Without the database checks restore() does. */
	public static function restorable(array $a): bool
	{
		$ok = static fn(?string $v) => $v === null || (!str_contains($v, self::CUT) && $v !== '•••');
		if ($a['source'] === 'log') return false;
		if ($a['action'] === 'delete') return true;
		return ($a['action'] === 'update' || ($a['action'] === 'restore' && $a['old_value'] !== null)) && $a['column_name'] !== '' && $a['old_value'] !== $a['new_value'] && $ok($a['old_value']) && $ok($a['new_value']);
	}

	/**
	 * Puts back one value (a changed column: its old value, only while the column still holds the new value) or a deleted row (all
	 * columns of that delete; only when no row with that key exists). Needs the save right on the form the change was made in.
	 * @return string message; throws RuntimeException
	 */
	public static function restore(string $auditId, string $viaForm = ''): string
	{
		if (NuDemo::active()) throw new RuntimeException(t('demo_not_available'));
		$db = nu_db();
		$a = $db->tableExists(self::TABLE) ? $db->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_audit_id = ?', [$auditId]) : null;
		if (!$a) throw new RuntimeException(t('aud_err_gone'));
		$formId = (string)$a['form_id'];
		if ($formId === '' && $viaForm !== '' && ($vm = NuFormMeta::load($viaForm)) && self::keyOf($vm, (string)$a['table_name']) !== null) $formId = $viaForm;   // import: the form it is restored from
		if ($formId === '' || !NuAcl::can($formId, 'save')) throw new RuntimeException(t('err_forbidden'));
		if (!self::restorable($a)) throw new RuntimeException(t('aud_err_cut'));
		$table = (string)$a['table_name'];
		$meta = NuFormMeta::load($formId);
		$columns = $meta && self::audited($table) && $db->tableExists($table) ? $db->columns($table) : [];
		$pk = $columns ? NuRecord::column($columns, (string)self::keyOf($meta, $table)) : null;
		if ($pk === null) throw new RuntimeException(t('aud_err_table'));
		$rid = (string)$a['record_id'];
		// the user must see the record and – for one value – see and change that field on the form (security review 0.8.367)
		$main = strcasecmp($table, $meta->table()) === 0 ? $rid : (string)$a['parent_id'];
		if ($main !== '' && NuRecord::load($meta, $main) !== null && !NuRecord::visible($meta, $main)) throw new RuntimeException(t('err_forbidden'));
		$cols = self::formColumns($meta);
		if ($cols !== null && $a['action'] !== 'delete') {
			$o = $cols[strtolower($table)][strtolower((string)$a['column_name'])] ?? null;
			if ($o === null || NuRecord::protectedField($o) || in_array((string)$o['sob_all_access'], ['1', '2', '4'], true)) throw new RuntimeException(t('err_forbidden'));
		}
		$ctx = ['source' => 'restore', 'action' => 'restore', 'form_id' => $formId, 'form_code' => (string)$a['form_code'], 'parent_table' => (string)$a['parent_table'], 'parent_id' => (string)$a['parent_id']];
		$exists = static fn() => (bool)$db->value('SELECT COUNT(*) FROM ' . NuDB::ident($table) . ' WHERE ' . NuDB::ident($pk) . ' = ?', [$rid]);

		if ($a['action'] === 'delete') {
			if ($exists()) throw new RuntimeException(t('aud_err_exists'));
			$row = [$pk => $rid];
			foreach ($db->all('SELECT column_name, old_value FROM ' . self::TABLE . ' WHERE change_id = ? AND table_name = ? AND record_id = ? AND action = ?',
				[(string)$a['change_id'], $table, $rid, 'delete']) as $r) {
				$c = NuRecord::column($columns, (string)$r['column_name']);
				if ($c === null || $c === $pk) continue;
				if ($r['old_value'] !== null && (str_contains((string)$r['old_value'], self::CUT) || $r['old_value'] === '•••')) throw new RuntimeException(t('aud_err_cut'));
				$row[$c] = $r['old_value'];
			}
			if (!empty($columns[$pk]['auto']) && $db->type === 'sqlsrv') throw new RuntimeException(t('aud_err_table'));
			self::begin();
			try {
				$db->insert($table, $row);
				self::record('insert', $table, $rid, [], $row, $ctx);
			} finally {
				self::end();
			}
			NuLog::write('audit_restore', (string)$a['form_code'], $rid, ['table' => $table, 'row' => true]);
			return t('aud_restored_row');
		}

		$c = NuRecord::column($columns, (string)$a['column_name']);
		if ($c === null || $c === $pk) throw new RuntimeException(t('aud_err_table'));
		$cur = $db->one('SELECT ' . NuDB::ident($c) . ' AS v FROM ' . NuDB::ident($table) . ' WHERE ' . NuDB::ident($pk) . ' = ?', [$rid]);
		if (!$cur) throw new RuntimeException(t('aud_err_record'));
		if (!self::same($cur['v'], $a['new_value'])) throw new RuntimeException(t('aud_err_changed'));
		self::begin();
		try {
			$db->update($table, [$c => $a['old_value']], $pk, $rid);
			self::record('update', $table, $rid, [$c => $cur['v']], [$c => $a['old_value']], $ctx);
		} finally {
			self::end();
		}
		NuLog::write('audit_restore', (string)$a['form_code'], $rid, ['table' => $table, 'column' => $c]);
		return t('aud_restored_value', $c);
	}

	// ================================================================= deleting (0.8.351)

	public const OLDER = [1, 2, 3, 6, 12];          // "older than" choices in months
	private const BATCH = 5000;

	/**
	 * Rows to delete from the dialog's fields: filtered ('1' = the list's current search / filters, $_GET), older ('' | months of OLDER |
	 * 'date'), before (Y-m-d for 'date'). Filtered rows are deleted exactly (a column filter may take part of a save); "older than" cuts by
	 * time, so a save (one moment) goes as a whole.
	 * @return array{from: string, params: array, filtered: bool, before: ?string, all: bool}
	 */
	public static function selection(array $in): array
	{
		[$from, $params, $active] = NuListView::scope('audit', true);
		$filtered = !empty($in['filtered']) && $active;
		if (!$filtered) [$from, $params] = NuListView::scope('audit', false);
		$before = null;
		$older = (string)($in['older'] ?? '');
		if ($older === 'date' && !nu_valid_date((string)($in['before'] ?? ''))) {        // "before a date" without a date: nothing, never all
			return ['from' => $from . (str_contains($from, ' WHERE ') ? ' AND ' : ' WHERE ') . '1 = 0', 'params' => $params, 'filtered' => $filtered, 'before' => null, 'all' => false];
		}
		if ($older === 'date') $before = (string)$in['before'];
		elseif (in_array((int)$older, self::OLDER, true)) $before = date('Y-m-d', (int)strtotime('-' . (int)$older . ' months'));
		if ($before !== null) {
			$from .= (str_contains($from, ' WHERE ') ? ' AND ' : ' WHERE ') . 'changed_at < ?';
			$params[] = nu_db()->datetime($before);
		}
		return ['from' => $from, 'params' => $params, 'filtered' => $filtered, 'before' => $before, 'all' => !$filtered && $before === null];
	}

	/** @return array{rows: int, changes: int} */
	public static function countSelection(array $sel): array
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return ['rows' => 0, 'changes' => 0];
		$r = $db->one('SELECT COUNT(*) AS n, COUNT(DISTINCT change_id) AS c' . $sel['from'], $sel['params']);
		return ['rows' => (int)($r['n'] ?? 0), 'changes' => (int)($r['c'] ?? 0)];
	}

	/** Deletes the selection in batches (short locks on big tables). @return int rows deleted */
	public static function deleteSelection(array $sel, string $by): int
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return 0;
		$count = self::countSelection($sel);
		$n = 0;
		if ($sel['all']) {
			$n = $db->run('DELETE FROM ' . self::TABLE)->rowCount();
		} else {
			for ($guard = 0; $guard < 100000; $guard++) {
				$ids = array_column($db->page('SELECT zzzzsys_nunext_audit_id AS id' . $sel['from'], $sel['params'], 'zzzzsys_nunext_audit_id', self::BATCH, 0), 'id');
				if (!$ids) break;
				$n += $db->run('DELETE FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_audit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->rowCount();
				if (count($ids) < self::BATCH) break;
			}
		}
		$filter = [];
		if ($sel['filtered']) {
			if (trim((string)($_GET['q'] ?? '')) !== '') $filter['q'] = (string)$_GET['q'];
			foreach (array_keys(NuListDefs::get('audit')['filters']) as $k) if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '' && $_GET[$k] !== '*') $filter[$k] = $_GET[$k];
		}
		NuLog::write('audit_deleted', self::TABLE, '', array_filter(['rows' => $n, 'changes' => $count['changes'], 'all' => $sel['all'] ?: null,
			'filter' => $filter ?: null, 'before' => $sel['before'], 'by' => $by]));
		return $n;
	}

	/** CSV of the selection (all columns, streamed in batches) – "Download first". Ends the request. */
	public static function csv(array $sel): never
	{
		session_write_close();
		nu_time_limit(0);
		$db = nu_db();
		$cols = ['changed_at', 'login', 'user_id', 'ip', 'action', 'source', 'table_name', 'record_id', 'form_code', 'form_id', 'parent_table', 'parent_id',
			'column_name', 'old_value', 'new_value', 'change_id', 'zzzzsys_nunext_audit_id'];
		$sep = (string)(nu_cfg('nuConfigCSVSeparator') ?: ';');
		if (!in_array($sep, [';', ',', "\t", '|'], true)) $sep = ';';
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="audit-trail-' . date('Y-m-d-His') . '.csv"');
		header('Cache-Control: no-store');
		$out = fopen('php://output', 'w');
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, $cols, $sep, '"', '');
		$n = 0;
		if ($db->tableExists(self::TABLE)) {
			for ($off = 0; ; $off += self::BATCH) {
				$rows = $db->page('SELECT ' . implode(', ', $cols) . $sel['from'], $sel['params'], 'changed_at, zzzzsys_nunext_audit_id', self::BATCH, $off);
				foreach ($rows as $r) {
					$line = [];
					foreach ($cols as $c) {
						$v = (string)($r[$c] ?? '');
						if ($v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v)) $v = "'" . $v;   // no formulas in Excel
						$line[] = $v;
					}
					fputcsv($out, $line, $sep, '"', '');
				}
				$n += count($rows);
				if (count($rows) < self::BATCH) break;
			}
		}
		fclose($out);
		NuLog::write('browse_exported', 'audit', '', ['format' => 'csv', 'rows' => $n, 'before_delete' => true]);
		exit;
	}

	// ================================================================= housekeeping, migration

	/** Rows older than keep_days. @return int deleted */
	public static function purge(): int
	{
		$days = self::settings()['keep_days'];
		if ($days <= 0 || !nu_db()->tableExists(self::TABLE)) return 0;
		return nu_db()->run('DELETE FROM ' . self::TABLE . ' WHERE changed_at < ?', [date('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
	}

	/** Migration 0011: the record changes of the log (record_inserted / record_updated / record_deleted) copied. @return int rows */
	public static function copyFromLog(): int
	{
		$db = nu_db();
		NuSchema::create();
		if (!$db->tableExists('zzzzsys_nunext_log') || $db->value("SELECT COUNT(*) FROM " . self::TABLE . " WHERE source = 'log'")) return 0;
		$forms = [];
		foreach ($db->all("SELECT zzzzsys_form_id, sfo_code FROM zzzzsys_form") as $f) $forms[(string)$f['sfo_code']] = (string)$f['zzzzsys_form_id'];
		$n = 0;
		$map = ['record_inserted' => 'insert', 'record_updated' => 'update', 'record_deleted' => 'delete'];
		foreach ($db->all("SELECT * FROM zzzzsys_nunext_log WHERE event IN ('record_inserted', 'record_updated', 'record_deleted') ORDER BY log_time") as $l) {
			$d = json_decode((string)$l['details'], true);
			if (!is_array($d)) continue;
			$table = (string)($d['table'] ?? '');
			$fields = is_array($d['fields'] ?? null) ? $d['fields'] : [];
			$base = ['change_id' => (string)$l['zzzzsys_nunext_log_id'], 'changed_at' => (string)$l['log_time'], 'user_id' => mb_substr((string)$l['user_id'], 0, 25),
				'login' => mb_substr((string)$l['login'], 0, 50), 'ip' => mb_substr((string)$l['ip'], 0, 45), 'action' => $map[(string)$l['event']], 'source' => 'log',
				'table_name' => mb_substr($table, 0, 100), 'record_id' => mb_substr((string)$l['record_id'], 0, 100), 'form_id' => $forms[(string)$l['object']] ?? '',
				'form_code' => mb_substr((string)$l['object'], 0, 100), 'parent_table' => '', 'parent_id' => ''];
			foreach ($fields as $col => $pair) {
				[$o, $v] = is_array($pair) ? array_pad(array_values($pair), 2, null) : [null, $pair];
				if ($base['action'] === 'delete' && $o === null) [$o, $v] = [$v, null];   // the log stored a delete as [null, value]
				$db->insert(self::TABLE, ['zzzzsys_nunext_audit_id' => nu_id(), 'column_name' => mb_substr((string)$col, 0, 100),
					'old_value' => $o === null ? null : self::value((string)$col, $o), 'new_value' => $v === null ? null : self::value((string)$col, $v)] + $base);
				$n++;
			}
		}
		return $n;
	}
}
