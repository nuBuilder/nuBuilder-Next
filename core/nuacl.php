<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Access control on top of zzzzsys_access_form, the same table nuBuilder uses.
 * A user may open a form when its access level has a row for that form; the slf_*_button
 * flags ('1') grant the individual actions. The globeadmin may do everything.
 *
 * nuBuilder Next's own screens are mapped to nuBuilder's system form IDs, so rights granted in
 * nuBuilder for "nuuser" / "nuaccess" apply here too.
 */
final class NuAcl
{
	public const FORM_USER   = 'nuuser';
	public const FORM_ACCESS = 'nuaccess';
	public const FORM_FORM   = 'nuform';      // form builder
	public const FORM_LOG    = 'nunextlog';   // login & change log (nuBuilder Next only)
	public const FORM_AUDIT  = 'nunextaudit'; // audit trail (nuBuilder Next only, 0.8.350)
	public const FORM_APICLIENT = 'nunextapiclient'; // API clients list (nuBuilder Next only, 0.8.547) – right through the admin right apiclients
	public const FORM_USERFIELD = 'nunextuserfield'; // custom user fields list (nuBuilder Next only, 0.8.546) – right through the admin right userfields
	public const FORM_SETUP  = 'nusetup';     // nuBuilder setup form (e-mail settings, test mail)
	public const FORM_EMAILLOG = 'nuemaillog'; // nuBuilder e-mail log
	public const FORM_FORMAT = 'nuformat';
	public const FORM_EMAILTEMPLATE = 'nuemailtemplate'; // nuBuilder e-mail templates
	public const FORM_ITEM   = 'nuitem';      // nuBuilder items
	public const FORM_FILE   = 'nufile';      // nuBuilder files (zzzzsys_file)
	public const FORM_TRANSLATE = 'nutranslate';   // nuBuilder translations (zzzzsys_translate)
	public const FORM_PROCEDURE = 'nuprocedure';   // nuBuilder procedures (zzzzsys_php)
	public const FORM_REPORT = 'nubuildreport';    // nuBuilder reports (zzzzsys_report) and the report designer
	public const FORM_SELECT = 'nuselect';         // nuBuilder SQL builder (zzzzsys_select)
	public const FORM_PERMISSION = 'nupermissionitem';   // nuBuilder permission items (zzzzsys_permission_item)
	public const FORM_PROMPT = 'nupromptgenerator';   // nuBuilder prompt generator (zzzzsys_prompt_generator)
	public const FORM_DEBUG  = 'nudebug';     // nuBuilder debug results (zzzzsys_debug)    // nuBuilder formats (zzzzsys_format)

	/** right => zzzzsys_access_form column */
	public const RIGHTS = [
		'add'    => 'slf_add_button',
		'save'   => 'slf_save_button',
		'delete' => 'slf_delete_button',
		'clone'  => 'slf_clone_button',
		'new'    => 'slf_new_button',
		'print'  => 'slf_print_button',
	];

	private static array $cache = [];

	/** @return array{view:bool,add:bool,save:bool,delete:bool,clone:bool,new:bool,print:bool,create:bool} */
	/**
	 * Besides the buttons (RIGHTS), nuBuilder's access level gives per form (0.8.461, as 4.9's nuform.php / nudata.php):
	 *   slf_form_type 0 = browse only (no edit screen, not even a new record), 1 = edit only (no browse list) → 'browse' / 'edit' false;
	 *   slf_data_mode 0 = no changes to existing records → 'no_edits' true and 'save' false (new records, Clone and Delete stay).
	 * The globeadmin and admin roles with "change" on the form are not limited (nuBuilder: global access).
	 */
	private const FREE = ['browse' => true, 'edit' => true, 'no_edits' => false];

	public static function rights(string $formId): array
	{
		$u = NuAuth::user();
		if (!$u) return self::none();
		if ($u['globeadmin']) return array_fill_keys(['view', 'create', ...array_keys(self::RIGHTS)], true) + self::FREE;

		$key = (string)($u['id'] ?? '') . '|' . $u['access_id'] . '|' . $formId;
		if (isset(self::$cache[$key])) return self::$cache[$key];
		if (NuImpersonate::readOnly()) {                        // globeadmin acting as a user, view only
			$r = self::rightsOf($u, $formId);
			foreach (['create', ...array_keys(self::RIGHTS)] as $k) if ($k !== 'print') $r[$k] = false;
			return self::$cache[$key] = $r;
		}
		$r = self::rightsOf($u, $formId);
		// nuBuilder Forte's User Home (0.8.602): open for a user whose access level has it as home form – a launch form without data
		if (!$r['view'] && $formId === NuUserHome::FORM && NuUserHome::isHomeOf($u)) $r = ['view' => true, 'print' => true] + self::none() + self::FREE;
		// admin role (0.8.323): rights on nuBuilder's system forms on top of the access level (core/nuadmin.php)
		$lvl = class_exists('NuAdmin') ? NuAdmin::formLevel($formId) : '';
		if ($lvl === 'change') $r = array_fill_keys(['view', 'create', ...array_keys(self::RIGHTS)], true) + self::FREE;
		elseif ($lvl === 'view') { $r['view'] = true; $r['print'] = true; $r['browse'] = $r['edit'] = true; }
		return self::$cache[$key] = $r;
	}

	/** Rights an access level gives on a form (zzzzsys_access_form only – no admin role, no globeadmin); for the self-test (0.8.361). */
	public static function levelRights(string $accessId, string $formId): array
	{
		return self::rightsOf(['access_id' => $accessId], $formId);
	}

	/**
	 * Rights of the user's levels combined (0.8.585): the main level plus active additional levels (NuUserAccess) – a right any level
	 * grants counts; browse / edit when any level allows it; "no changes to existing records" only when every level with the form says so.
	 */
	private static function rightsOf(array $u, string $formId): array
	{
		$levels = isset($u['id']) ? NuUserAccess::levels($u) : [(string)($u['access_id'] ?? '')];
		if (count($levels) <= 1) return self::levelRow((string)($levels[0] ?? ($u['access_id'] ?? '')), $formId);
		$out = null;
		foreach ($levels as $lv) {
			$r = self::levelRow($lv, $formId);
			if (!$r['view']) continue;
			if ($out === null) { $out = $r; continue; }
			foreach ($r as $k => $v) $out[$k] = $k === 'no_edits' ? ($out[$k] && $v) : ($out[$k] || $v);
		}
		return $out ?? self::none();
	}

	/** Rights one access level gives on a form (zzzzsys_access_form). */
	private static function levelRow(string $accessId, string $formId): array
	{
		$u = ['access_id' => $accessId];

		$row = nu_db()->one('SELECT ' . implode(', ', self::RIGHTS) . '
			FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ? AND slf_zzzzsys_form_id = ?', [$u['access_id'], $formId]);

		if (!$row) return self::none();

		$r = ['view' => true];
		foreach (self::RIGHTS as $right => $col) $r[$right] = (string)$row[$col] === '1';
		$r['create'] = $r['add'] || $r['new'];     // "Add" on the list, "New" on the edit screen: either one allows creating
		// form type / data mode (0.8.461) – columns of nuBuilder 4.9 (older databases lack them: no limit)
		$r += self::FREE;
		$cols = nu_db()->columns('zzzzsys_access_form');
		if (isset($cols['slf_form_type']) || isset($cols['slf_data_mode'])) {
			$m = nu_db()->one('SELECT ' . implode(', ', array_keys(array_intersect_key(['slf_form_type' => 1, 'slf_data_mode' => 1], $cols)))
				. ' FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ? AND slf_zzzzsys_form_id = ?', [$u['access_id'], $formId]) ?? [];
			$ft = trim((string)($m['slf_form_type'] ?? ''));
			if ($ft === '0') $r['edit'] = false;              // browse only
			if ($ft === '1') $r['browse'] = false;            // edit only
			if (trim((string)($m['slf_data_mode'] ?? '')) === '0') { $r['no_edits'] = true; $r['save'] = false; }
		}
		return $r;
	}

	public static function can(string $formId, string $right = 'view'): bool
	{
		return self::rights($formId)[$right] ?? false;
	}

	/** Stops with 403 unless the right is granted. */
	public static function require(string $formId, string $right = 'view'): void
	{
		if (self::can($formId, $right)) return;
		http_response_code(403);
		nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true);
	}

	private static function none(): array
	{
		return array_fill_keys(['view', 'create', ...array_keys(self::RIGHTS)], false) + ['browse' => false, 'edit' => false, 'no_edits' => false];
	}
}
