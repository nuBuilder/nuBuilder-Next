<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder "hash cookies": #RECORD_ID#, #USER_ID#, #field_id#, #property# … replaced in SQL, PHP and HTML.
 * Unknown hashes stay untouched (nuBuilder behaviour; browse SQL often tests LOCATE('#', '#x#')).
 *
 * Unlike nuBuilder, values are escaped for the context they are inserted into, so a field value
 * cannot break out of a quoted SQL or PHP string.
 */
final class NuHash
{
	public const SQL  = 'sql';
	public const PHP  = 'php';
	public const HTML = 'html';
	public const RAW  = 'raw';

	/** User / session part of the hash. */
	public static function base(): array
	{
		static $base = null;
		if ($base !== null) return $base;

		$u = NuAuth::user();
		$h = [
			'USER_ID' => $u['id'], 'LOGIN_NAME' => $u['login'], 'USER_LOGIN' => $u['login'], 'USER_NAME' => $u['display'], 'USER_EMAIL' => $u['email'],
			'SESSION_ID' => self::sessionId(),
			'GLOBAL_ACCESS' => $u['globeadmin'] ? '1' : '0', 'ACCESS_LEVEL_ID' => $u['access_id'],
			'USER_GROUP_ID' => $u['access_id'],   // nuBuilder's name of the access level id (nuGetUserAccess, 0.8.581)
			'ACCESS_LEVEL_CODE' => '', 'ACCESS_LEVEL_GROUP' => '', 'USER_LANGUAGE' => nu_lang_to_db(nu_lang()),
			'USER_CODE' => '', 'USER_DEPARTMENT' => '', 'USER_TEAM' => '', 'USER_POSITION' => '',
			'USER_ADDITIONAL1' => '', 'USER_ADDITIONAL2' => '', 'USER_FIRST_NAME' => '', 'USER_LAST_NAME' => '',
			// nuBuilder: home form, language as stored (German, English …), developer mode (globeadmin + $nuConfigDevMode), permission items
			'HOME_ID' => $u['globeadmin'] ? 'nuhome' : '', 'LANGUAGE' => nu_lang_to_db(nu_lang()),
			'DEV_MODE' => self::devMode() ? '1' : '0', 'USER_PERMISSIONS' => '',
		];
		$db = nu_db();
		if (!$u['globeadmin']) {
			$row = $db->one('SELECT * FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$u['id']]) ?? [];
			foreach (['CODE' => 'sus_code', 'DEPARTMENT' => 'sus_department', 'TEAM' => 'sus_team', 'POSITION' => 'sus_position',
					  'ADDITIONAL1' => 'sus_additional1', 'ADDITIONAL2' => 'sus_additional2', 'FIRST_NAME' => 'sus_first_name', 'LAST_NAME' => 'sus_last_name'] as $k => $col) {
				$h['USER_' . $k] = (string)($row[$col] ?? '');
			}
			$acc = $db->one('SELECT sal_code, sal_group, sal_zzzzsys_form_id FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$u['access_id']]);
			$h['ACCESS_LEVEL_CODE']  = (string)($acc['sal_code'] ?? '');
			$h['ACCESS_LEVEL_GROUP'] = (string)($acc['sal_group'] ?? '');
			$h['HOME_ID']            = (string)($acc['sal_zzzzsys_form_id'] ?? '');
			if (trim((string)($row['sus_language'] ?? '')) !== '') $h['LANGUAGE'] = (string)$row['sus_language'];
			$h['USER_PERMISSIONS']   = implode(', ', self::permissions((string)$u['id']));
			if (array_key_exists('sus_name', $row)) $h['USER_NAME'] = (string)$row['sus_name'];   // nuBuilder: sus_name, not first + last name (0.8.462)
		}
		// custom user fields (0.8.454): #USER_<CODE># – empty for the globeadmin (no user record)
		foreach (NuUserFields::mine() + array_map(static fn() => '', NuUserFields::all()) as $code => $val) {
			$k = 'USER_' . strtoupper((string)$code);
			if (!isset($h[$k])) $h[$k] = (string)$val;
		}
		return $base = $h;
	}

	/** Variables offered by the code editor's "#" menu, grouped and described in the reader's language. */
	public const CATALOG = [
		'user'    => ['USER_ID', 'LOGIN_NAME', 'USER_NAME', 'USER_EMAIL', 'USER_CODE', 'USER_FIRST_NAME', 'USER_LAST_NAME', 'USER_POSITION', 'USER_DEPARTMENT',
			'USER_TEAM', 'USER_ADDITIONAL1', 'USER_ADDITIONAL2', 'USER_LANGUAGE', 'LANGUAGE', 'USER_PERMISSIONS', 'ACCESS_LEVEL_ID', 'USER_GROUP_ID', 'ACCESS_LEVEL_CODE',
			'ACCESS_LEVEL_GROUP', 'GLOBAL_ACCESS', 'HOME_ID', 'DEV_MODE', 'SESSION_ID'],
		'form'    => ['FORM_ID', 'FORM_CODE', 'FORM_GROUP', 'RECORD_ID', 'NEW_RECORD', 'PREVIOUS_RECORD_ID', 'TABLE_ID', 'ADV_SEARCH'],
		'context' => ['ID', 'CODE', 'SUBFORM_ID', 'OBJECT_ID', 'ROW', 'PROCEDURE_CODE'],
		'date'    => ['nu_date', 'nu_date_time', 'nu_time', 'nu_year', 'nu_month', 'nu_day', 'nu_hour', 'nu_minute'],
	];

	public static function catalog(): array
	{
		$groups = [];
		foreach (self::CATALOG as $g => $names) {
			$items = [];
			foreach ($names as $n) $items[] = [$n, t('hv_' . strtolower($n))];
			if ($g === 'user') foreach (NuUserFields::all() as $code => $f) $items[] = ['USER_' . strtoupper((string)$code), t('hv_user_field', NuUserFields::label($f))];   // 0.8.454
			$groups[] = ['title' => t('hv_group_' . $g), 'items' => $items];
		}
		return ['title' => t('hv_title'), 'search' => t('hv_search'), 'empty' => t('hv_empty'), 'fields' => t('hv_group_fields'),
			'globals' => t('hv_group_globals'), 'hint' => t('hv_hint'), 'groups' => $groups];
	}

	/** nuBuilder's developer mode: globeadmin and $nuConfigDevMode = true. */
	public static function devMode(): bool
	{
		$u = NuAuth::user();
		return $u && $u['globeadmin'] && empty($u['demo']) && !empty(nu_cfg('nuConfigDevMode'));
	}

	/** Active permission items of a user (nuBuilder: zzzzsys_user_permission → zzzzsys_permission_item.pme_ident). */
	public static function permissions(string $userId): array
	{
		$db = nu_db();
		if (!$db->tableExists('zzzzsys_user_permission') || !$db->tableExists('zzzzsys_permission_item')) return [];
		try {
			return array_map('strval', array_column($db->all("SELECT pme_ident FROM zzzzsys_user_permission
				LEFT JOIN zzzzsys_permission_item ON zzzzsys_permission_item_id = upe_ident
				WHERE upe_zzzzsys_user_id = ? AND COALESCE(upe_active, '0') = '1' AND pme_ident IS NOT NULL ORDER BY pme_ident", [$userId]), 'pme_ident'));
		} catch (Throwable) {
			return [];
		}
	}

	/** nuBuilder's date / time variables (#nu_date# …), server time zone, taken fresh for every hash. */
	public static function now(): array
	{
		$d = new DateTimeImmutable();
		return ['nu_date_time' => $d->format('Y-m-d H:i:s'), 'nu_date' => $d->format('Y-m-d'), 'nu_time' => $d->format('H:i:s'),
			'nu_year' => $d->format('Y'), 'nu_month' => $d->format('m'), 'nu_day' => $d->format('d'), 'nu_hour' => $d->format('H'), 'nu_minute' => $d->format('i')];
	}

	/** Replaces #key# for every key in $hash. */
	public static function replace(?string $s, array $hash, string $context = self::RAW): string
	{
		$s = (string)$s;
		if ($s === '' || !str_contains($s, '#')) return $s;

		$map = [];
		foreach ($hash as $k => $v) {
			if (!is_string($k) || $k === '') continue;
			if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
			$map['#' . $k . '#'] = self::escape((string)$v, $context);
		}
		return strtr($s, $map);
	}

	public static function escape(string $v, string $context): string
	{
		return match ($context) {
			self::SQL  => nu_db()->escapeString($v),           // also with MySQL's NO_BACKSLASH_ESCAPES (0.8.207)
			self::PHP  => str_replace(['\\', "'", '"', '$'], ['\\\\', "\\'", '\\"', '\\$'], $v),
			self::HTML => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
			default    => $v,
		};
	}

	/**
	 * Hash of a form. Order (first wins): system variables → field values of the record → form properties (nuSetProperty)
	 * → global hash cookies (nuSetProperty(…, true)). A field or property can never replace #USER_ID#, #RECORD_ID# …
	 * $extra: context variables of the request (#ID#, #CODE# in lookups, #SUBFORM_ID#), treated as system variables.
	 */
	public static function forForm(NuFormMeta $meta, string $recordId, array $values = [], array $props = [], array $extra = []): array
	{
		return self::build($meta, $recordId, $values, $props, $extra)[0];
	}

	/** Hash without a form (procedure test run): user, session, date variables, $extra system variables, then $props. */
	public static function plain(array $props = [], array $extra = []): array
	{
		$h = self::base() + self::now() + $extra + ['FORM_ID' => '', 'FORM_CODE' => '', 'RECORD_ID' => '', 'NEW_RECORD' => '0', 'TABLE_ID' => self::tableId()];
		foreach ($props + NuHashCookies::all() as $k => $v) if (is_string($k) && $k !== '' && !array_key_exists($k, $h)) $h[$k] = $v;
		return $h + self::lowerAliases($h['FORM_ID'], $h['RECORD_ID']);
	}

	/** Every hash variable with its source (system, user, record, property, global) – for the hash viewer. */
	public static function explain(NuFormMeta $meta, string $recordId, array $values = [], array $props = []): array
	{
		[$h, $src] = self::build($meta, $recordId, $values, $props, []);
		$out = [];
		foreach ($h as $k => $v) $out[] = ['name' => (string)$k, 'value' => is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE), 'source' => $src[$k]];
		return $out;
	}

	private static function build(NuFormMeta $meta, string $recordId, array $values, array $props, array $extra): array
	{
		$src = [];
		$h = [];
		foreach (self::base() as $k => $v) { $h[$k] = $v; $src[$k] = str_starts_with($k, 'USER_') || in_array($k, ['LOGIN_NAME', 'ACCESS_LEVEL_ID', 'ACCESS_LEVEL_CODE', 'ACCESS_LEVEL_GROUP', 'GLOBAL_ACCESS', 'HOME_ID', 'LANGUAGE'], true) ? 'user' : 'system'; }
		$sys = [
			'FORM_ID' => $meta->id, 'FORM_CODE' => (string)$meta->form['sfo_code'], 'FORM_GROUP' => (string)($meta->form['sfo_group'] ?? ''),
			'RECORD_ID' => $recordId, 'NEW_RECORD' => $recordId === '-1' ? '1' : '0',
			'TABLE_ID' => self::tableId(), 'PREVIOUS_RECORD_ID' => $recordId,
		] + self::now() + $extra;
		foreach ($sys as $k => $v) { $h[$k] = $v; $src[$k] = 'system'; }
		foreach (['record' => $values, 'property' => $props, 'global' => NuHashCookies::all()] as $from => $list) {
			foreach ($list as $k => $v) {
				if (!is_string($k) || $k === '' || array_key_exists($k, $h)) continue;
				$h[$k] = $v;
				$src[$k] = $from;
			}
		}
		// nuBuilder's lower-case names (0.8.576): nuBuilder copies the request fields form_id / record_id (and, in hidden procedures,
		// hash_record_id) into the hash – here the same values as #FORM_ID# / #RECORD_ID#. A field, property or hash cookie of that
		// name comes first, as in nuBuilder (the edit form's values overwrite them there).
		foreach (self::lowerAliases($h['FORM_ID'], $h['RECORD_ID']) as $k => $v) if (!array_key_exists($k, $h)) { $h[$k] = $v; $src[$k] = 'system'; }
		return [$h, $src];
	}

	/** @return array<string, string> #form_id#, #record_id#, #hash_record_id# */
	private static function lowerAliases(mixed $formId, mixed $recordId): array
	{
		return ['form_id' => (string)$formId, 'record_id' => (string)$recordId, 'hash_record_id' => (string)$recordId];
	}

	/** Names a property may not take (system and user variables). */
	public static function isSystem(string $name): bool
	{
		return array_key_exists($name, self::base()) || array_key_exists($name, self::now())
			|| in_array($name, ['FORM_ID', 'FORM_CODE', 'FORM_GROUP', 'RECORD_ID', 'NEW_RECORD', 'TABLE_ID', 'PREVIOUS_RECORD_ID', 'ID', 'CODE', 'SUBFORM_ID', 'PROCEDURE_CODE', 'OBJECT_ID'], true);
	}

	/**
	 * #SESSION_ID#: nuBuilder's session id when logged in through nuBuilder, otherwise a random id of this nuBuilder Next session
	 * (never the PHP session id – hash values end up in SQL and HTML).
	 */
	public static function sessionId(): string
	{
		return NuHashCookies::nuBuilderSession() ?? ($_SESSION['nu_hash_session'] ??= 'nx' . bin2hex(random_bytes(10)));
	}

	/** Unique temp table name for #TABLE_ID# within this request. */
	public static function tableId(): string
	{
		static $id = null;
		return $id ??= '___nu1' . substr(md5(session_id() . uniqid('', true)), 0, 15);
	}
}
