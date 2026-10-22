<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Saved form cloner settings (0.8.276) – nuBuilder's table zzzzsys_cloner, the same rows nuBuilder's Cloner form ("nucloner")
 * reads and writes, so settings saved in one are opened in the other.
 *
 * Mapping (nuBuilder column → Cloner page):
 *   clo_form_source, clo_form_dest ('' = new form)   source, dest (+ dest_mode)
 *   clo_tabs              JSON list of tab ids (nuBuilder's multi select) – tabs; empty = all
 *   clo_objects           1 = WITHOUT objects (nuBuilder's checkbox "Without Objects") – objects inverted
 *   clo_subforms_include, clo_subforms          subforms on/off, JSON list of the subforms' FORM ids (nuBuilder) ↔ the page's
 *                                               subform OBJECT ids of the source form
 *   clo_iframe_forms_include, clo_iframe_forms  the same for iframe (run method i) forms
 *   clo_dump, clo_new_pks (1 = new keys = not keep_pks), clo_notes
 *   clo_json              {"nunext": {"access": bool, "replace": bool}} – options nuBuilder's table has no column for
 *   clo_tables_include / clo_tables_exclude     only nuBuilder's (its table export) – kept as they are
 * Code and description of a new form are not saved: each run suggests a free code.
 */
final class NuClonerConfig
{
	public const TABLE = 'zzzzsys_cloner';

	/** Creates the table with nuBuilder's structure when it is missing (installer, update step 0004, first use). */
	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists(self::TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$flag = $db->type === 'mysql' ? 'TINYINT(1)' : ($db->type === 'sqlsrv' ? 'TINYINT' : 'INTEGER');
		$db->run('CREATE TABLE ' . self::TABLE . ' (zzzzsys_cloner_id ' . $T('id') . ' NOT NULL PRIMARY KEY, clo_form_source ' . $T('id') . ' NULL, '
			. 'clo_form_dest ' . $T('id') . ' NULL, clo_tabs ' . $T('text', '1000') . " NULL, clo_dump $flag NULL, clo_objects $flag NULL, "
			. "clo_subforms_include $flag NULL, clo_subforms " . $T('text', '1000') . " NULL, clo_iframe_forms_include $flag NULL, "
			. 'clo_iframe_forms ' . $T('longtext') . ' NULL, clo_tables_include ' . $T('longtext') . ' NULL, clo_tables_exclude ' . $T('longtext') . ' NULL, '
			. "clo_new_pks $flag NULL, clo_notes " . $T('longtext') . ' NULL, clo_json ' . $T('longtext') . ' NULL)'
			. ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
		NuDB::forgetColumns(self::TABLE);
	}

	/** @return array<int, array<string, mixed>> rows with the codes of both forms */
	public static function all(string $q = ''): array
	{
		$where = ''; $p = [];
		if ($q !== '') {
			$where = " WHERE (fs.sfo_code LIKE ? ESCAPE '!' OR fs.sfo_description LIKE ? ESCAPE '!' OR fd.sfo_code LIKE ? ESCAPE '!' OR c.clo_notes LIKE ? ESCAPE '!')";
			$p = array_fill(0, 4, NuDB::like($q));
		}
		return nu_db()->all('SELECT c.*, fs.sfo_code AS source_code, fs.sfo_description AS source_description, fd.sfo_code AS dest_code FROM ' . self::TABLE . ' c '
			. 'LEFT JOIN zzzzsys_form fs ON fs.zzzzsys_form_id = c.clo_form_source LEFT JOIN zzzzsys_form fd ON fd.zzzzsys_form_id = c.clo_form_dest'
			. $where . ' ORDER BY fs.sfo_code, c.zzzzsys_cloner_id', $p);
	}

	public static function get(string $id): ?array
	{
		return $id === '' ? null : nu_db()->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_cloner_id = ?', [$id]);
	}

	/** @return string[] */
	private static function list(mixed $v): array
	{
		$a = json_decode((string)$v, true);
		return is_array($a) ? array_values(array_filter(array_map('strval', $a), static fn($s) => (string)$s !== '')) : [];
	}

	/**
	 * Subform / iframe objects of a form: object id => the form they show. $type 'subform' | 'iframe'.
	 * @return array<string, string>
	 */
	private static function objectForms(string $formId, string $type): array
	{
		if ($formId === '') return [];
		$sql = $type === 'subform'
			? "SELECT zzzzsys_object_id AS id, sob_subform_zzzzsys_form_id AS f FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_type = 'subform'"
			: "SELECT zzzzsys_object_id AS id, sob_run_zzzzsys_form_id AS f FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_type = 'run' AND sob_run_method = 'i'";
		$out = [];
		foreach (nu_db()->all($sql, [$formId]) as $r) if ((string)$r['f'] !== '') $out[(string)$r['id']] = (string)$r['f'];
		return $out;
	}

	/** The page's input array from a row (over the page's defaults $def). */
	public static function toInput(array $row, array $def): array
	{
		$in = $def;
		$in['source'] = (string)($row['clo_form_source'] ?? '');
		$in['dest'] = (string)($row['clo_form_dest'] ?? '');
		$in['dest_mode'] = $in['dest'] !== '' ? 'existing' : 'new';
		$in['tabs'] = self::list($row['clo_tabs'] ?? '');
		$in['objects'] = (string)($row['clo_objects'] ?? '') !== '1';
		foreach (['subform' => ['subforms', 'subform_ids', 'clo_subforms_include', 'clo_subforms'], 'iframe' => ['iframes', 'iframe_ids', 'clo_iframe_forms_include', 'clo_iframe_forms']]
			as $type => [$on, $ids, $colOn, $col]) {
			$in[$on] = (string)($row[$colOn] ?? '') === '1';
			$forms = self::list($row[$col] ?? '');
			$in[$ids] = $forms ? array_keys(array_filter(self::objectForms($in['source'], $type), static fn($f) => in_array($f, $forms, true))) : [];
		}
		$in['dump'] = (string)($row['clo_dump'] ?? '') === '1';
		$in['keep_pks'] = (string)($row['clo_new_pks'] ?? '1') === '0';
		$in['notes'] = (string)($row['clo_notes'] ?? '');
		$x = json_decode((string)($row['clo_json'] ?? ''), true);
		$x = is_array($x) && is_array($x['nunext'] ?? null) ? $x['nunext'] : [];
		$in['access'] = !empty($x['access']);
		$in['replace'] = !empty($x['replace']);
		return $in;
	}

	/** Saves the page's input; $id '' = new row. @return array{0: ?string, 1: array<string, string>} */
	public static function save(array $in, string $id = ''): array
	{
		$errors = [];
		if ((string)$in['source'] === '') $errors['source'] = t('err_required');
		if ($in['dest_mode'] === 'existing' && (string)$in['dest'] === '') $errors['dest'] = t('err_required');
		if ($errors) return [null, $errors];
		$db = nu_db();
		$formsOf = static function (string $type, array $ids) use ($in): string {
			$map = self::objectForms((string)$in['source'], $type);
			$forms = array_values(array_unique(array_values(array_intersect_key($map, array_flip($ids)))));
			return json_encode($forms ?: array_values(array_unique(array_values($map))), JSON_UNESCAPED_UNICODE);   // none chosen = all, as the page
		};
		$old = $id !== '' ? self::get($id) : null;
		$json = json_decode((string)($old['clo_json'] ?? ''), true);
		if (!is_array($json)) $json = [];
		$json['nunext'] = ['access' => (bool)$in['access'], 'replace' => (bool)$in['replace']];
		$row = [
			'clo_form_source' => (string)$in['source'], 'clo_form_dest' => $in['dest_mode'] === 'existing' ? (string)$in['dest'] : '',
			'clo_tabs' => $in['tabs'] ? json_encode(array_values($in['tabs']), JSON_UNESCAPED_UNICODE) : '',
			'clo_objects' => $in['objects'] ? 0 : 1,
			'clo_subforms_include' => $in['subforms'] ? 1 : 0, 'clo_subforms' => $formsOf('subform', $in['subform_ids']),
			'clo_iframe_forms_include' => $in['iframes'] ? 1 : 0, 'clo_iframe_forms' => $formsOf('iframe', $in['iframe_ids']),
			'clo_dump' => $in['dump'] ? 1 : 0, 'clo_new_pks' => $in['keep_pks'] ? 0 : 1, 'clo_notes' => (string)$in['notes'],
			'clo_json' => json_encode($json, JSON_UNESCAPED_UNICODE),
		];
		if (mb_strlen($row['clo_tabs']) > 1000 || mb_strlen($row['clo_subforms']) > 1000) return [null, ['source' => t('err_too_long', 1000)]];
		if ($old) {
			$db->update(self::TABLE, $row, 'zzzzsys_cloner_id', $id);
		} else {
			$id = nu_id();
			$db->insert(self::TABLE, ['zzzzsys_cloner_id' => $id] + $row);
		}
		return [$id, []];
	}
}
