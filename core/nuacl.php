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
	public static function rights(string $formId): array
	{
		$u = NuAuth::user();
		if (!$u) return self::none();
		if ($u['globeadmin']) return array_fill_keys(['view', 'create', ...array_keys(self::RIGHTS)], true);

		$key = $u['access_id'] . '|' . $formId;
		if (isset(self::$cache[$key])) return self::$cache[$key];
		if (NuImpersonate::readOnly()) {                        // globeadmin acting as a user, view only
			$r = self::rightsOf($u, $formId);
			foreach ($r as $k => $v) if ($k !== 'view' && $k !== 'print') $r[$k] = false;
			return self::$cache[$key] = $r;
		}
		$r = self::rightsOf($u, $formId);
		// admin role (0.8.323): rights on nuBuilder's system forms on top of the access level (core/nuadmin.php)
		$lvl = class_exists('NuAdmin') ? NuAdmin::formLevel($formId) : '';
		if ($lvl === 'change') $r = array_fill_keys(array_keys($r), true);
		elseif ($lvl === 'view') { $r['view'] = true; $r['print'] = true; }
		return self::$cache[$key] = $r;
	}

	/** Rights an access level gives on a form (zzzzsys_access_form only – no admin role, no globeadmin); for the self-test (0.8.361). */
	public static function levelRights(string $accessId, string $formId): array
	{
		return self::rightsOf(['access_id' => $accessId], $formId);
	}

	private static function rightsOf(array $u, string $formId): array
	{

		$row = nu_db()->one('SELECT ' . implode(', ', self::RIGHTS) . '
			FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ? AND slf_zzzzsys_form_id = ?', [$u['access_id'], $formId]);

		if (!$row) return self::none();

		$r = ['view' => true];
		foreach (self::RIGHTS as $right => $col) $r[$right] = (string)$row[$col] === '1';
		$r['create'] = $r['add'] || $r['new'];     // "Add" on the list, "New" on the edit screen: either one allows creating
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
		return array_fill_keys(['view', 'create', ...array_keys(self::RIGHTS)], false);
	}
}
