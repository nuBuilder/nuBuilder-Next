<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder Forte's "User Home" (0.8.602): the launch form nuuserhome. Forte's Fast Form builder puts a run button for every new
 * form on its tab "Fast Forms" (nufastforms); the globeadmin home links to it, an access level may have it as its home form.
 * The New form wizard does the same here (same rows as nuBuilder Forte's nuFFInsertRunButton, so Forte shows the button too);
 * the start page has a built-in tile "User Home"; a user whose access level has nuuserhome as home form may open it (NuAcl::rights)
 * and finds it on the automatic start page (NuHome::autoLayout).
 */
final class NuUserHome
{
	public const FORM = 'nuuserhome';
	public const TAB  = 'nufastforms';

	public static function available(): bool
	{
		return isset(nu_forms()[self::FORM]);
	}

	/**
	 * Creates User Home when the database has none (0.8.618) – as nuBuilder Forte's installation does: the form row and its two tabs
	 * (Main, Fast Forms) from install/nuuserhome.json (nuBuilder Forte 4.9's nubuilder4.sql, same ids), only the columns the tables have.
	 * Runs on installation and with every update (NuMigrate::run); an existing User Home is never changed. @return bool true = created
	 */
	public static function ensure(): bool
	{
		$db = nu_db();
		if (!$db->tableExists('zzzzsys_form') || !$db->tableExists('zzzzsys_tab')) return false;
		if ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [self::FORM])) return false;
		$def = json_decode((string)@file_get_contents(NU_ROOT . '/install/nuuserhome.json'), true);
		if (!is_array($def) || !is_array($def['form'] ?? null)) return false;
		$only = static function (array $row, array $cols): array {
			$have = array_flip(array_map('strtolower', $cols));
			return array_filter($row, static fn($k) => isset($have[strtolower((string)$k)]), ARRAY_FILTER_USE_KEY);
		};
		$db->insert('zzzzsys_form', $only($def['form'], array_keys($db->columns('zzzzsys_form'))));
		$tabCols = array_keys($db->columns('zzzzsys_tab'));
		foreach ((array)($def['tabs'] ?? []) as $tab) {
			if (!is_array($tab) || (int)$db->value('SELECT COUNT(*) FROM zzzzsys_tab WHERE zzzzsys_tab_id = ?', [(string)($tab['zzzzsys_tab_id'] ?? '')])) continue;
			$db->insert('zzzzsys_tab', $only($tab, $tabCols));
		}
		NuLog::write('userhome_created', 'zzzzsys_form', self::FORM, []);
		return true;
	}

	/** A run button for the form on the tab Fast Forms (the tab is made when missing). @return bool false = nuuserhome missing */
	public static function addButton(string $formId, string $label, string $type): bool
	{
		if (!self::available()) return false;
		$db = nu_db();
		if (!(int)$db->value('SELECT COUNT(*) FROM zzzzsys_tab WHERE zzzzsys_tab_id = ?', [self::TAB])) {
			$db->insert('zzzzsys_tab', ['zzzzsys_tab_id' => self::TAB, 'syt_zzzzsys_form_id' => self::FORM, 'syt_title' => 'Fast Forms', 'syt_order' => -1]);
		}
		if ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_run_zzzzsys_form_id = ?', [self::FORM, $formId])) return true;
		$top = (int)($db->value('SELECT MAX(sob_all_top) FROM zzzzsys_object WHERE sob_all_zzzzsys_tab_id = ?', [self::TAB]) ?? 0) + 50;
		$db->insert('zzzzsys_object', [
			'zzzzsys_object_id' => nu_id(), 'sob_all_zzzzsys_form_id' => self::FORM, 'sob_all_zzzzsys_tab_id' => self::TAB, 'sob_all_id' => 'ff' . $formId,
			'sob_all_label' => mb_substr($label, 0, 300), 'sob_all_table' => '', 'sob_all_order' => 11, 'sob_all_top' => $top, 'sob_all_left' => 30,
			'sob_all_width' => 150, 'sob_all_height' => 30, 'sob_run_zzzzsys_form_id' => $formId,
			'sob_run_id' => str_starts_with($type, 'browse') ? '' : '-1',      // as nuBuilder Forte: browse forms open their list, the others a new record
			'sob_run_method' => 'b', 'sob_all_cloneable' => '0', 'sob_all_validate' => '0', 'sob_all_access' => '0', 'sob_all_align' => 'center', 'sob_all_type' => 'run',
		]);
		NuFormMeta::forget(self::FORM);
		NuLog::write('userhome_button', self::FORM, $formId, ['label' => $label]);
		return true;
	}

	/** Is nuuserhome the home form of one of the user's access levels (main or additional)? */
	public static function isHomeOf(array $u): bool
	{
		$lv = NuUserAccess::levels($u);
		if (!$lv) return false;
		$qm = implode(', ', array_fill(0, count($lv), '?'));
		try {
			return (int)nu_db()->value("SELECT COUNT(*) FROM zzzzsys_access WHERE sal_zzzzsys_form_id = ? AND zzzzsys_access_id IN ($qm)", array_merge([self::FORM], $lv)) > 0;
		} catch (Throwable) {
			return false;
		}
	}
}
