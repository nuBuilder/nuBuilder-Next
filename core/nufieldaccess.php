<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Field data access (0.8.480, handover spec 04): server-enforced modes per object, with rules per access level, user, permission item,
 * team or department (NuSubject – the most specific rule wins, a tie goes to the more open mode). Rules and the default mode live in
 * zzzzsys_nunext_object_access; nuBuilder's zzzzsys_object gets no column (sob_all_access is set to the nearest nuBuilder value).
 *
 *   edit  editable                       ro / hide  nuBuilder's read only / hidden: cosmetic, saved unless "protect_fields"
 *   prot  locked, never saved            ins / upd  saved only when the record is new / exists
 *   mask  shows only a mask, never saved wh         withheld: not in the page, the list, exports or the audit view; never saved
 *
 * Without rows an object keeps nuBuilder's access (0–4, 9). The globeadmin sees nuBuilder's access only, unless the Security setting
 * "Apply data access to the globeadmin" is on. Server code (hooks, procedures, reports) keeps the real values.
 */
final class NuFieldAccess
{
	public const TABLE = 'zzzzsys_nunext_object_access';
	/** From open to closed – a tie between rules of the same rank goes to the first. */
	public const MODES = ['edit', 'upd', 'ins', 'ro', 'prot', 'mask', 'hide', 'wh'];
	/** Nearest nuBuilder access for the default mode (nuBuilder 4.9 on the same database). */
	public const NUB_ACCESS = ['edit' => '0', 'ro' => '1', 'hide' => '2', 'prot' => '1', 'ins' => '1', 'upd' => '1', 'mask' => '3', 'wh' => '3'];
	/** Object types the modes apply to (values of the record). */
	public const FIELD_TYPES = ['input', 'select', 'textarea', 'calc', 'lookup', 'editor', 'display'];

	private static array $rules = [];      // form id => object id => list of rules
	private static array $cache = [];      // object id + record => [mode, rule|null]
	private static ?array $as = null;      // preview as another user

	public static function reset(): void
	{
		self::$rules = [];
		self::$cache = [];
	}

	/** Rules of the objects of a form (one query per form and request). @return array<string, list<array>> */
	public static function rulesOfForm(string $formId): array
	{
		if (isset(self::$rules[$formId])) return self::$rules[$formId];
		$out = [];
		try {
			if (nu_db()->tableExists(self::TABLE)) {
				foreach (nu_db()->all('SELECT * FROM ' . self::TABLE . ' WHERE noa_form_id = ?', [$formId]) as $r) {
					$out[(string)$r['noa_object_id']][] = ['id' => (string)$r['zzzzsys_nunext_object_access_id'], 'type' => (string)$r['noa_subject_type'],
						'subject' => (string)$r['noa_subject'], 'mode' => (string)$r['noa_mode'], 'mask' => (string)$r['noa_mask'],
						'condition' => (string)$r['noa_condition'], 'note' => (string)$r['noa_note']];
				}
			}
		} catch (Throwable $e) {
			error_log('nuBuilder Next field access: ' . $e->getMessage());
		}
		return self::$rules[$formId] = $out;
	}

	public static function rulesOf(array $o): array
	{
		return self::rulesOfForm((string)($o['sob_all_zzzzsys_form_id'] ?? ''))[(string)($o['zzzzsys_object_id'] ?? '')] ?? [];
	}

	/** nuBuilder's access as a mode (access 9 = its condition). */
	public static function legacy(array $o, ?bool $ga = null): string
	{
		$ga ??= self::me()['ga'];
		return match (NuRecord::accessOf($o)) {
			'1' => 'ro',
			'2' => 'hide',
			'3' => $ga ? 'edit' : 'hide',
			'4' => $ga ? 'ro' : 'hide',
			default => 'edit',
		};
	}

	private static function me(): array
	{
		return self::$as ?? NuSubject::me();
	}

	/** Effective mode and the rule that decided it (null = nuBuilder's access). @return array{0: string, 1: ?array} */
	public static function resolve(array $o): array
	{
		$key = (string)($o['zzzzsys_object_id'] ?? '') . "\x1f" . (string)(NuRecord::$accessHash['RECORD_ID'] ?? '') . "\x1f" . (self::$as['id'] ?? '');
		if (isset(self::$cache[$key])) return self::$cache[$key];
		$rules = self::rulesOf($o);
		$me = self::me();
		if (!$rules || ($me['ga'] && !NuSecurity::get('field_access_ga'))) return self::$cache[$key] = [self::legacy($o), null];
		$win = NuSubject::best($rules, self::MODES, static fn(array $r) => self::conditionOk($o, $r), $me);
		if ($win === null) return self::$cache[$key] = [self::legacy($o), null];
		return self::$cache[$key] = [in_array($win['mode'], self::MODES, true) ? $win['mode'] : 'edit', $win];
	}

	public static function mode(array $o): string
	{
		return self::resolve($o)[0];
	}

	/** A rule's condition: SQL with the form's hash variables; counts only when the first value is 1 – an error never counts. */
	private static function conditionOk(array $o, array $r): bool
	{
		$cond = trim((string)($r['condition'] ?? ''));
		if ($cond === '') return true;
		try {
			$sql = NuHash::replace($cond, NuRecord::$accessHash ?: NuHash::plain(), NuHash::SQL);
			$rows = nu_db()->readOnly(static fn() => nu_db()->all($sql));
			return $rows && (string)(array_values($rows[0])[0] ?? '') === '1';
		} catch (Throwable $e) {
			error_log('nuBuilder Next field access condition of ' . ($o['sob_all_id'] ?? '?') . ': ' . $e->getMessage());
			return false;
		}
	}

	public static function hidden(array $o): bool
	{
		$m = self::mode($o);
		return $m === 'hide' || $m === 'wh';
	}

	public static function withheld(array $o): bool
	{
		return in_array($o['sob_all_type'] ?? '', self::FIELD_TYPES, true) && self::mode($o) === 'wh';
	}

	public static function masked(array $o): bool
	{
		return in_array($o['sob_all_type'] ?? '', self::FIELD_TYPES, true) && self::mode($o) === 'mask';
	}

	/** Shown locked in the form ($isNew: the record does not exist yet). */
	public static function readonly(array $o, bool $isNew): bool
	{
		$m = self::mode($o);
		return in_array($m, ['ro', 'prot', 'mask'], true) || ($m === 'ins' && !$isNew) || ($m === 'upd' && $isNew);
	}

	/** May the value posted by the browser be saved? */
	public static function writable(array $o, bool $isNew): bool
	{
		[$m, $rule] = self::resolve($o);
		if ($rule === null) return !NuRecord::protectedField($o);          // nuBuilder's access – as before (Security setting protect_fields)
		return match ($m) {
			'prot', 'mask', 'wh' => false,
			'ins' => $isNew,
			'upd' => !$isNew,
			'ro', 'hide' => !NuSecurity::get('protect_fields'),
			default => true,
		};
	}

	/** Mask pattern of the deciding rule (or the default row). */
	public static function maskOf(array $o): string
	{
		$r = self::resolve($o)[1];
		return trim((string)($r['mask'] ?? '')) ?: 'last:4';
	}

	/** last:N (•••• 1234), first:N, email (a•••@example.com), text:… (a fixed text). */
	public static function maskValue(mixed $value, string $pattern): string
	{
		$v = (string)($value ?? '');
		if ($v === '') return '';
		[$kind, $arg] = array_pad(explode(':', $pattern, 2), 2, '');
		$n = max(0, min(20, (int)$arg));
		return match ($kind) {
			'first' => mb_substr($v, 0, $n) . ' ••••',
			'email' => str_contains($v, '@') ? mb_substr($v, 0, 1) . '•••@' . substr(strrchr($v, '@'), 1) : '••••',
			'text'  => $arg,
			default => '•••• ' . ($n > 0 && mb_strlen($v) > $n ? mb_substr($v, -$n) : ''),
		};
	}

	/** What the browser may see of a value: '' for withheld, the mask for masked, else the value. */
	public static function shown(array $o, mixed $value): mixed
	{
		if (self::withheld($o)) return '';
		if (self::masked($o)) return self::maskValue($value, self::maskOf($o));
		return $value;
	}

	/** The form's hash for the page (HTML objects, breadcrumb title): withheld fields empty, masked ones masked. */
	public static function scrubHash(NuFormMeta $meta, array $hash): array
	{
		foreach ($meta->objects as $o) {
			$f = (string)$o['sob_all_id'];
			if (!array_key_exists($f, $hash) || !in_array($o['sob_all_type'], self::FIELD_TYPES, true)) continue;
			$hash[$f] = self::shown($o, $hash[$f]);
		}
		return $hash;
	}

	/** Object of a browse column: sbr_display is the field name (optionally "alias.field"). */
	public static function columnObject(NuFormMeta $meta, string $display): ?array
	{
		$d = trim($display, " \t\n\r`[]\"");
		if (str_contains($d, '.')) $d = substr($d, strrpos($d, '.') + 1);
		$d = trim($d, '`[]"');
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $d)) return null;
		foreach ($meta->objects as $o) if (strcasecmp((string)$o['sob_all_id'], $d) === 0 && in_array($o['sob_all_type'], self::FIELD_TYPES, true)) return $o;
		return null;
	}

	// ================================================================= preview, editing

	/** Runs $fn as $userId (Data access → "As user"). */
	public static function asUser(string $userId, callable $fn): mixed
	{
		$keepAs = self::$as;
		$keepCache = self::$cache;
		self::$as = NuSubject::forUser($userId);
		self::$cache = [];
		try {
			return $fn();
		} finally {
			self::$as = $keepAs;
			self::$cache = $keepCache;
		}
	}

	/**
	 * Saves the rules of one object: [{type, subject, mode, mask, condition, note}] incl. one 'default' row (or none = nuBuilder's access).
	 * The default mode also sets sob_all_access to the nearest nuBuilder value (only when it is a new mode).
	 */
	public static function saveObject(array $o, array $rules): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$objId = (string)$o['zzzzsys_object_id'];
		$formId = (string)$o['sob_all_zzzzsys_form_id'];
		$before = self::rulesOfForm($formId)[$objId] ?? [];
		$seen = [];
		$clean = [];
		foreach ($rules as $r) {
			$type = (string)($r['type'] ?? '');
			$mode = (string)($r['mode'] ?? '');
			$subject = $type === 'default' ? '' : mb_substr(trim((string)($r['subject'] ?? '')), 0, 100);
			if (!isset(NuSubject::RANK[$type]) || !in_array($mode, self::MODES, true) || ($type !== 'default' && $subject === '')) continue;
			$k = $type . "\x1f" . mb_strtolower($subject);
			if (isset($seen[$k])) continue;
			$seen[$k] = true;
			$clean[] = ['type' => $type, 'subject' => $subject, 'mode' => $mode, 'mask' => mb_substr(trim((string)($r['mask'] ?? '')), 0, 50),
				'condition' => mb_substr(trim((string)($r['condition'] ?? '')), 0, 1000), 'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 500)];
		}
		$by = mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50);
		$db->transaction(static function (NuDB $db) use ($objId, $formId, $clean, $by) {
			$db->run('DELETE FROM ' . self::TABLE . ' WHERE noa_object_id = ?', [$objId]);
			foreach ($clean as $r) {
				$db->insert(self::TABLE, ['zzzzsys_nunext_object_access_id' => nu_id(), 'noa_object_id' => $objId, 'noa_form_id' => $formId,
					'noa_subject_type' => $r['type'], 'noa_subject' => $r['subject'], 'noa_mode' => $r['mode'], 'noa_mask' => $r['mask'] ?: null,
					'noa_condition' => $r['condition'] ?: null, 'noa_note' => $r['note'] ?: null, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $by]);
			}
		});
		foreach ($clean as $r) {
			if ($r['type'] === 'default' && in_array($r['mode'], ['prot', 'ins', 'upd', 'mask', 'wh'], true) && (string)$o['sob_all_access'] !== '9') {
				$db->update('zzzzsys_object', ['sob_all_access' => self::NUB_ACCESS[$r['mode']]], 'zzzzsys_object_id', $objId);
			}
		}
		$strip = static fn(array $l) => array_map(static fn($r) => array_diff_key($r, ['id' => 1]), $l);
		if ($strip($before) !== $clean) NuLog::write('field_access_saved', self::TABLE, $objId, ['form' => $formId, 'field' => (string)$o['sob_all_id'], 'before' => $strip($before), 'after' => $clean]);
		unset(self::$rules[$formId]);
		self::$cache = [];
	}

	/** Housekeeping: rules of objects that no longer exist. @return int removed rows */
	public static function purgeOrphans(): int
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return 0;
		return $db->run('DELETE FROM ' . self::TABLE . ' WHERE NOT EXISTS (SELECT 1 FROM zzzzsys_object o WHERE '
			. $db->sameText('o.zzzzsys_object_id', self::TABLE . '.noa_object_id') . ')')->rowCount();
	}

	/** Number of rules per form (Security check, form list). */
	public static function counts(): array
	{
		try {
			if (!nu_db()->tableExists(self::TABLE)) return [];
			$out = [];
			foreach (nu_db()->all('SELECT noa_form_id, COUNT(*) AS n FROM ' . self::TABLE . ' GROUP BY noa_form_id') as $r) $out[(string)$r['noa_form_id']] = (int)$r['n'];
			return $out;
		} catch (Throwable) {
			return [];
		}
	}
}
