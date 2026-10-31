<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Custom user fields (0.8.454): extra fields of the user form, defined by an admin (page ?p=userfields, admin right userfields).
 *   Definitions: zzzzsys_nunext_user_field (code, label, type, options, required, unique, default, hint, edit_by, in_list, sort).
 *   Values:      zzzzsys_nunext_user_ext – one row per user, one REAL column per field (created / dropped with the field), key
 *                zzzzsys_user_id in the collation of zzzzsys_user, so SQL joins work everywhere:
 *                SELECT … FROM zzzzsys_user LEFT JOIN zzzzsys_nunext_user_ext USING (zzzzsys_user_id)
 *   Used by:     user form (tab More), My account (?p=account, fields the user edits himself), users list (columns / filters), user CSV
 *                import, hash variables #USER_<CODE>#, nuNextUserField() in PHP and JavaScript, REST /v1/users ("fields"), start page
 *                conditions (cond.fields), the log (user_updated).
 */
final class NuUserFields
{
	public const TABLE = 'zzzzsys_nunext_user_field';
	public const EXT = 'zzzzsys_nunext_user_ext';
	public const KEY = 'zzzzsys_user_id';
	/** type => [NuDDL type, length, max characters (0 = none)] */
	public const TYPES = [
		'text' => ['text', '255', 255], 'longtext' => ['longtext', '', 0], 'integer' => ['bigint', '', 20], 'number' => ['decimal', '18,4', 30],
		'date' => ['date', '', 10], 'yesno' => ['text', '1', 1], 'email' => ['text', '255', 255], 'phone' => ['text', '50', 50],
		'url' => ['text', '1000', 1000], 'select' => ['text', '255', 255],
	];
	public const EDIT_BY = ['admin', 'globeadmin', 'user'];
	public const MAX_FIELDS = 100;
	/** Codes that would clash with nuBuilder's #USER_…# variables or the key column. */
	private const RESERVED = ['id', 'code', 'name', 'email', 'login', 'department', 'team', 'position', 'additional1', 'additional2', 'first_name',
		'last_name', 'language', 'permissions', 'zzzzsys_user_id', 'user_id'];

	private static ?array $all = null;

	public static function reset(): void
	{
		self::$all = null;
	}

	/** Field definitions, in their order (code => row). [] when the table is missing. */
	public static function all(): array
	{
		if (self::$all !== null) return self::$all;
		self::$all = [];
		try {
			$db = nu_db();
			if (!$db->tableExists(self::TABLE)) return self::$all;
			foreach ($db->all('SELECT * FROM ' . self::TABLE . ' ORDER BY sort, code') as $r) {
				if (!isset(self::TYPES[(string)$r['type']])) continue;
				self::$all[(string)$r['code']] = $r;
			}
		} catch (Throwable) {
		}
		return self::$all;
	}

	/** Fields exist and their table is there. */
	public static function ready(): bool
	{
		return self::all() !== [] && nu_db()->tableExists(self::EXT);
	}

	public static function label(array $f): string
	{
		return NuTranslate::text((string)$f['label']);
	}

	public static function hint(array $f): string
	{
		return (string)$f['hint'] === '' ? '' : NuTranslate::text((string)$f['hint']);
	}

	// ================================================================= definitions

	public static function validCode(string $code): bool
	{
		return (bool)preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code) && !in_array($code, self::RESERVED, true);
	}

	/** The values table – created with the first field, in zzzzsys_user's collation (joins), MySQL and SQL Server. */
	private static function ensureExt(): void
	{
		$db = nu_db();
		if ($db->tableExists(self::EXT)) return;
		$sql = 'CREATE TABLE ' . NuDB::ident(self::EXT) . ' (' . NuDB::ident(self::KEY) . ' ' . NuDDL::sqlType('id') . ' NOT NULL PRIMARY KEY)';
		if ($db->type === 'mysql') $sql .= NuDDL::mysqlTableOptions('zzzzsys_user');
		$db->run($sql);
		NuDB::forgetColumns(self::EXT);
	}

	/**
	 * Checks and normalises a definition from the form. @param array<string, mixed> $in @return array{0: array<string, mixed>, 1: array<string, string>} [row, errors]
	 */
	public static function check(array $in, ?array $old): array
	{
		$e = [];
		$row = [];
		if ($old === null) {
			$code = strtolower(trim((string)($in['code'] ?? '')));
			if ($code === '') $e['code'] = t('err_required');
			elseif (!self::validCode($code)) $e['code'] = t('uf_err_code');
			elseif (isset(self::all()[$code])) $e['code'] = t('uf_err_code_taken');
			$type = (string)($in['type'] ?? '');
			if (!isset(self::TYPES[$type])) $e['type'] = t('err_invalid');
			$row['code'] = $code;
			$row['type'] = $type;
		} else {
			$type = (string)$old['type'];
		}
		$row['label'] = mb_substr(trim((string)($in['label'] ?? '')), 0, 200);
		if ($row['label'] === '') $e['label'] = t('err_required');
		$row['hint'] = mb_substr(trim((string)($in['hint'] ?? '')), 0, 1000);
		$row['options'] = $type === 'select' ? trim(str_replace("\r\n", "\n", (string)($in['options'] ?? ''))) : '';
		if ($type === 'select') {
			if ($row['options'] === '') $e['options'] = t('err_required');
			elseif (self::isSql($row['options'])) {
				try { self::options(['options' => $row['options'], 'type' => 'select']); } catch (Throwable $x) { $e['options'] = t('uf_err_sql') . ' ' . mb_substr($x->getMessage(), 0, 200); }
			}
		}
		$row['required'] = !empty($in['required']) ? '1' : '0';
		$row['is_unique'] = !empty($in['is_unique']) && in_array($type, ['text', 'integer', 'email', 'phone', 'url'], true) ? '1' : '0';
		$row['edit_by'] = in_array((string)($in['edit_by'] ?? ''), self::EDIT_BY, true) ? (string)$in['edit_by'] : 'admin';
		$row['in_list'] = !empty($in['in_list']) ? '1' : '0';
		$def = trim((string)($in['default_value'] ?? ''));
		if ($def !== '' && !isset($e['type'])) {
			$err = self::checkValue(['type' => $type, 'options' => $row['options'], 'code' => $row['code'] ?? $old['code'], 'is_unique' => '0', 'required' => '0'], $def, null);
			if ($err !== null) $e['default_value'] = $err;
		}
		$row['default_value'] = $def === '' ? '' : (string)self::normalise($type, $def);
		return [$row, $e];
	}

	/** A new field: definition row + real column. @return string the new id */
	public static function add(array $row): string
	{
		if (count(self::all()) >= self::MAX_FIELDS) throw new RuntimeException(t('uf_err_max', self::MAX_FIELDS));
		NuSchema::ensure();
		$db = nu_db();
		self::ensureExt();
		[$type, $len] = self::TYPES[$row['type']];
		NuDDL::addColumn(self::EXT, $row['code'], $type, $len);
		if ($row['is_unique'] === '1' || $row['type'] === 'select') NuDDL::ensureIndex(self::EXT, $row['code']);
		$id = nu_id();
		$sort = (int)$db->value('SELECT COALESCE(MAX(sort), 0) FROM ' . self::TABLE) + 10;
		$u = NuAuth::user();
		$db->insert(self::TABLE, ['zzzzsys_nunext_user_field_id' => $id] + $row + ['sort' => $sort, 'created_at' => date('Y-m-d H:i:s'), 'created_by' => (string)($u['login'] ?? '')]);
		self::reset();
		NuLog::write('user_field_added', self::TABLE, $id, ['code' => $row['code'], 'type' => $row['type'], 'label' => $row['label']]);
		return $id;
	}

	public static function update(string $id, array $row): void
	{
		$old = self::byId($id);
		if (!$old) throw new RuntimeException(t('record_not_found'));
		$u = NuAuth::user();
		nu_db()->update(self::TABLE, $row + ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => (string)($u['login'] ?? '')], 'zzzzsys_nunext_user_field_id', $id);
		if (($row['is_unique'] ?? '0') === '1') NuDDL::ensureIndex(self::EXT, (string)$old['code']);
		self::reset();
		$diff = NuLog::diff($old, $row);
		if ($diff) NuLog::write('user_field_changed', self::TABLE, $id, ['code' => $old['code'], 'fields' => $diff]);
	}

	/** Removes the field and its column (the values are gone). @return int users that had a value */
	public static function delete(string $id): int
	{
		$f = self::byId($id);
		if (!$f) return 0;
		$db = nu_db();
		$col = NuDB::ident((string)$f['code']);
		$n = 0;
		if ($db->tableExists(self::EXT) && isset(array_change_key_case((array)$db->columns(self::EXT), CASE_LOWER)[strtolower((string)$f['code'])])) {
			$n = (int)$db->value('SELECT COUNT(*) FROM ' . self::EXT . " WHERE $col IS NOT NULL");
			if ($db->type === 'sqlsrv') {                       // SQL Server: indexes on the column first
				foreach ($db->all('SELECT i.name FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id'
					. ' JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id WHERE i.object_id = OBJECT_ID(?) AND c.name = ? AND i.is_primary_key = 0', [self::EXT, $f['code']]) as $ix) {
					$db->run('DROP INDEX ' . NuDB::ident((string)$ix['name']) . ' ON ' . NuDB::ident(self::EXT));
				}
			}
			$db->run('ALTER TABLE ' . NuDB::ident(self::EXT) . ' DROP COLUMN ' . $col);
			NuDB::forgetColumns(self::EXT);
		}
		$db->delete(self::TABLE, 'zzzzsys_nunext_user_field_id', $id);
		self::reset();
		NuLog::write('user_field_deleted', self::TABLE, $id, ['code' => $f['code'], 'label' => $f['label'], 'values' => $n]);
		return $n;
	}

	/** Moves a field one place up (-1) or down (1). */
	public static function move(string $id, int $dir): void
	{
		$list = array_values(self::all());
		$i = array_search($id, array_column($list, 'zzzzsys_nunext_user_field_id'), true);
		if ($i === false || !isset($list[$i + $dir])) return;
		[$list[$i], $list[$i + $dir]] = [$list[$i + $dir], $list[$i]];
		foreach ($list as $k => $f) nu_db()->update(self::TABLE, ['sort' => ($k + 1) * 10], 'zzzzsys_nunext_user_field_id', (string)$f['zzzzsys_nunext_user_field_id']);
		self::reset();
	}

	public static function byId(string $id): ?array
	{
		foreach (self::all() as $f) if ((string)$f['zzzzsys_nunext_user_field_id'] === $id) return $f;
		return null;
	}

	/** login;value of a field for every user with a value (CSV before deleting the field). */
	public static function csv(array $f): string
	{
		$db = nu_db();
		$sep = (string)(nu_cfg('nuConfigCSVSeparator') ?: ';');
		$q = static fn(string $v) => preg_match('/["\r\n;,\t|]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
		$out = "\xEF\xBB\xBF" . $q(t('user_login_name')) . $sep . $q(self::label($f)) . "\r\n";
		if (!$db->tableExists(self::EXT)) return $out;
		$col = NuDB::ident((string)$f['code']);
		foreach ($db->all('SELECT u.sus_login_name, x.' . $col . ' AS v FROM ' . self::EXT . ' x JOIN zzzzsys_user u ON ' . $db->sameText('u.zzzzsys_user_id', 'x.' . self::KEY)
			. ' WHERE x.' . $col . ' IS NOT NULL ORDER BY u.sus_login_name') as $r) $out .= $q((string)$r['sus_login_name']) . $sep . $q((string)$r['v']) . "\r\n";
		return $out;
	}

	// ================================================================= values

	/** Values of a user: code => string ('' = none). */
	public static function values(string $userId): array
	{
		$out = array_map(static fn() => '', self::all());
		if (!$out || $userId === '' || !nu_db()->tableExists(self::EXT)) return $out;
		$row = nu_db()->one('SELECT * FROM ' . self::EXT . ' WHERE ' . self::KEY . ' = ?', [$userId]) ?? [];
		$row = array_change_key_case($row, CASE_LOWER);
		foreach ($out as $code => $_) $out[$code] = self::display((string)self::all()[$code]['type'], $row[$code] ?? null);
		return $out;
	}

	/** Value as text: dates YYYY-MM-DD, numbers without trailing zeros, yes/no 1 / 0. */
	private static function display(string $type, mixed $v): string
	{
		if ($v === null) return '';
		$s = (string)$v;
		if ($type === 'date') return substr($s, 0, 10);
		if ($type === 'number' && str_contains($s, '.')) return rtrim(rtrim($s, '0'), '.');
		return $s;
	}

	/** Shown text of a value (lists, start page): choice label, yes / no, date d.m.Y. */
	public static function text(array $f, mixed $v): string
	{
		$v = self::display((string)$f['type'], $v);
		if ($v === '') return '';
		return match ((string)$f['type']) {
			'yesno' => $v === '1' ? t('yes') : t('no'),
			'date' => nu_valid_date($v) ? date(nu_dfmt('d.m.Y'), (int)strtotime($v)) : $v,
			'select' => (string)(self::options($f)[$v] ?? $v),
			default => $v,
		};
	}

	public static function isSql(string $o): bool
	{
		return (bool)preg_match('/^\s*(SELECT|WITH)\b/i', $o);
	}

	/** Choices of a select field: value => label (lines "value|label" / "value", or a SELECT: first column value, second label). */
	public static function options(array $f): array
	{
		static $cache = [];
		$o = (string)($f['options'] ?? '');
		if (isset($cache[$o])) return $cache[$o];
		$out = [];
		if (self::isSql($o)) {
			if (!NuDemo::readOnlySql($o)) throw new RuntimeException(t('uf_err_sql_read'));
			foreach (nu_db()->readOnly(static fn($db) => $db->all($o)) as $r) {
				$r = array_values($r);
				$v = (string)($r[0] ?? '');
				if ($v !== '') $out[$v] = (string)($r[1] ?? $v);
			}
		} else {
			foreach (explode("\n", $o) as $line) {
				$line = trim($line);
				if ($line === '') continue;
				[$v, $l] = array_pad(explode('|', $line, 2), 2, null);
				$v = trim($v);
				if ($v !== '') $out[$v] = trim((string)($l ?? $v)) ?: $v;
			}
		}
		return $cache[$o] = array_slice($out, 0, 1000, true);
	}

	/** Value as stored: numbers with a dot, yes / no 1 / 0, dates YYYY-MM-DD. */
	private static function normalise(string $type, string $v): string
	{
		$v = trim($v);
		if ($type === 'number') return str_replace([' ', "'", ','], ['', '', '.'], $v);
		if ($type === 'integer') return str_replace([' ', "'"], '', $v);
		if ($type === 'yesno') return in_array(mb_strtolower($v), ['1', 'yes', 'ja', 'true', 'x', 'y', 'j', 'oui', 'si', 'sì', 'on'], true) ? '1' : '0';
		return $v;
	}

	/** Error text for a value, null = fine. $userId: the user it belongs to (unique check), null = do not check unique. */
	public static function checkValue(array $f, string $v, ?string $userId): ?string
	{
		$type = (string)$f['type'];
		$v = trim($v);
		if ($v === '') return (string)$f['required'] === '1' ? t('err_required') : null;
		$n = self::normalise($type, $v);
		$max = self::TYPES[$type][2];
		if ($max && mb_strlen($n) > $max) return t('err_too_long', $max);
		$bad = match ($type) {
			'integer' => !preg_match('/^-?\d{1,18}$/', $n),
			'number' => !preg_match('/^-?\d{1,14}(\.\d{1,4})?$/', $n),
			'date' => !nu_valid_date($n),
			'email' => !filter_var($n, FILTER_VALIDATE_EMAIL),
			'url' => !preg_match('~^https?://\S+$~i', $n),
			'phone' => !preg_match('/^[0-9+()\/.\-\s]{3,50}$/', $n),
			'select' => !array_key_exists($n, self::options($f)),
			default => false,
		};
		if ($bad) return t('uf_err_value_' . ($type === 'select' ? 'choice' : 'format'));
		if ($userId !== null && (string)$f['is_unique'] === '1' && nu_db()->tableExists(self::EXT)) {
			$col = NuDB::ident((string)$f['code']);
			if ((int)nu_db()->value('SELECT COUNT(*) FROM ' . self::EXT . " WHERE $col = ? AND " . self::KEY . ' <> ?', [$n, $userId])) return t('uf_err_unique');
		}
		return null;
	}

	/**
	 * Validates posted values of the fields the current user may change. @param array<string, string> $in code => value
	 * @return array{0: array<string, string>, 1: array<string, string>} [normalised values, errors]
	 */
	public static function validate(array $in, string $userId, string $as = 'admin'): array
	{
		$vals = [];
		$errors = [];
		foreach (self::editable($as) as $code => $f) {
			if (!array_key_exists($code, $in)) continue;
			$v = is_scalar($in[$code]) ? (string)$in[$code] : '';
			$err = self::checkValue($f, $v, $userId);
			if ($err !== null) { $errors[$code] = $err; continue; }
			$vals[$code] = trim($v) === '' ? '' : self::normalise((string)$f['type'], $v);
		}
		return [$vals, $errors];
	}

	/**
	 * Fields one may change: 'admin' = user form with the save right (globeadmin fields only for the globeadmin), 'self' = My account
	 * (edit_by user), 'api' = REST (not the globeadmin fields), 'import' = CSV import (as admin).
	 */
	public static function editable(string $as = 'admin'): array
	{
		$ga = !empty(NuAuth::user()['globeadmin']);
		return array_filter(self::all(), static fn($f) => match ($as) {
			'self' => $f['edit_by'] === 'user',
			'api' => $f['edit_by'] !== 'globeadmin',
			default => $f['edit_by'] !== 'globeadmin' || $ga,
		});
	}

	/** Defaults of the fields for a new user. */
	public static function defaults(): array
	{
		return array_map(static fn($f) => (string)$f['default_value'], self::all());
	}

	/** Stores values (code => normalised value, '' = NULL). @return array<string, array{0: string, 1: string}> changes for the log */
	public static function save(string $userId, array $vals): array
	{
		$all = self::all();
		$vals = array_intersect_key($vals, $all);
		if (!$vals || $userId === '') return [];
		NuSchema::ensure();
		self::ensureExt();
		$db = nu_db();
		$old = self::values($userId);
		$row = [];
		$diff = [];
		foreach ($vals as $code => $v) {
			if ((string)$old[$code] === (string)$v) continue;
			$row[$code] = $v === '' ? null : $v;
			$diff['field:' . $code] = [$old[$code], $v];
		}
		if (!$row) return [];
		if ($db->value('SELECT COUNT(*) FROM ' . self::EXT . ' WHERE ' . self::KEY . ' = ?', [$userId])) $db->update(self::EXT, $row, self::KEY, $userId);
		else $db->insert(self::EXT, [self::KEY => $userId] + $row);
		return $diff;
	}

	/** Removes the values of a deleted user. */
	public static function forget(string $userId): void
	{
		try {
			if (nu_db()->tableExists(self::EXT)) nu_db()->run('DELETE FROM ' . self::EXT . ' WHERE ' . self::KEY . ' = ?', [$userId]);
		} catch (Throwable) {
		}
	}

	/**
	 * Input of a field for the user form and My account: a Bootstrap column with label, (i) tip, the input (name uf[<code>]) and the
	 * error. $readOnly: shown, not changeable (the field is edited by someone else).
	 */
	public static function inputHtml(array $f, string $value, ?string $error, bool $readOnly, bool $showType = false): string
	{
		$code = (string)$f['code'];
		$id = 'uf_' . $code;
		$name = 'uf[' . $code . ']';
		$type = (string)$f['type'];
		$req = (string)$f['required'] === '1';
		$inv = $error !== null ? ' is-invalid' : '';
		$ro = $readOnly ? ' disabled' : '';
		$attr = ' id="' . h($id) . '" name="' . h($name) . '"' . ($req && !$readOnly ? ' required' : '') . $ro . ' data-uf="' . h($code) . '"';
		$tip = self::hint($f) !== '' ? ' <span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h(self::hint($f))
			. '" title="' . h(self::hint($f)) . '"><i class="bi bi-info-circle"></i></span>' : '';
		$kind = $showType ? ' <span class="text-body-secondary small nuUfType">(' . h(t('uf_type_' . $type)) . ')</span>' : '';   // user form (0.8.508)
		$label = '<label class="form-label" for="' . h($id) . '">' . h(self::label($f)) . $kind . ($req ? ' <span class="text-danger">*</span>' : '') . '</label>' . $tip;
		$max = self::TYPES[$type][2];
		$ml = $max ? ' maxlength="' . $max . '"' : '';
		if ($type === 'yesno') {
			$input = '<div class="form-check form-switch mt-1">' . ($readOnly ? '' : '<input type="hidden" name="' . h($name) . '" value="0">')
				. '<input class="form-check-input' . $inv . '" type="checkbox" role="switch" value="1"' . $attr . ($value === '1' ? ' checked' : '') . '>'
				. '<label class="form-check-label" for="' . h($id) . '">' . h(self::label($f)) . $kind . '</label>' . $tip . '</div>';
			$label = '';
		} elseif ($type === 'longtext') {
			$input = '<textarea class="form-control' . $inv . '" rows="3"' . $attr . '>' . h($value) . '</textarea>';
		} elseif ($type === 'select') {
			$opts = '<option value=""></option>';
			try { $choices = self::options($f); } catch (Throwable) { $choices = []; }
			if ($value !== '' && !array_key_exists($value, $choices)) $choices = [$value => $value] + $choices;
			foreach ($choices as $v => $l) $opts .= '<option value="' . h((string)$v) . '"' . ((string)$v === $value ? ' selected' : '') . '>' . h((string)$l) . '</option>';
			$input = '<select class="form-select' . $inv . '"' . $attr . '>' . $opts . '</select>';
		} else {
			$html = ['date' => 'date', 'email' => 'email', 'url' => 'url', 'phone' => 'tel'][$type] ?? 'text';
			$mode = $type === 'integer' ? ' inputmode="numeric"' : ($type === 'number' ? ' inputmode="decimal"' : '');
			$input = '<input type="' . $html . '" class="form-control' . $inv . '"' . $attr . $ml . $mode . ' value="' . h($value) . '" autocomplete="off">';
		}
		$wide = in_array($type, ['longtext', 'url'], true) ? 'col-12' : 'col-sm-6';
		return '<div class="' . $wide . ' nuUf" data-uf-type="' . h($type) . '">' . $label . $input
			. ($error !== null ? '<div class="invalid-feedback d-block">' . h($error) . '</div>' : '') . '</div>';
	}

	/** Values of the signed-in user (hash variables, nuNextUserField in JavaScript); [] for the globeadmin. */
	public static function mine(): array
	{
		static $m = null;
		if ($m !== null) return $m;
		$u = NuAuth::user();
		return $m = (!$u || !empty($u['globeadmin'])) ? [] : self::values((string)$u['id']);
	}

	/** One value: of the signed-in user or of $userId; null = no such field. */
	public static function get(string $code, ?string $userId = null): ?string
	{
		$code = strtolower($code);
		if (!isset(self::all()[$code])) return null;
		$vals = $userId === null ? self::mine() : self::values($userId);
		return (string)($vals[$code] ?? '');
	}
}
