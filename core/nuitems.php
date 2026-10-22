<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Items (zzzzsys_item, nuBuilder "Items": values for lists, menus …, optionally bound to an object). */
final class NuItem
{
	/** column => max length (0 = unlimited) */
	public const FIELDS = [
		'itm_value' => 1000, 'itm_description' => 0, 'itm_group' => 100, 'itm_category' => 255, 'itm_object_id' => 25, 'itm_sort_order' => 0,
		'itm_tag' => 1000, 'itm_additional1' => 0, 'itm_additional2' => 2000, 'itm_notes' => 2000, 'itm_css' => 100, 'itm_icon' => 100,
		'itm_language' => 25, 'itm_parent_id' => 25, 'itm_active' => 1, 'itm_access_level' => 2000,
	];

	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists('zzzzsys_item')) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$db->run('CREATE TABLE zzzzsys_item (zzzzsys_item_id ' . $T('id') . ' NOT NULL PRIMARY KEY, itm_value ' . $T('text', '1000') . ' NULL, '
			. 'itm_description ' . $T('longtext') . ' NULL, itm_group ' . $T('text', '100') . ' NULL, itm_category ' . $T('text', '255') . ' NULL, '
			. 'itm_object_id ' . $T('id') . ' NULL, itm_sort_order ' . $T('int') . ' NOT NULL DEFAULT 0, itm_tag ' . $T('text', '1000') . ' NULL, '
			. 'itm_additional1 ' . $T('longtext') . ' NULL, itm_additional2 ' . $T('text', '2000') . ' NULL, itm_notes ' . $T('text', '2000') . ' NULL, '
			. 'itm_css ' . $T('text', '100') . ' NULL, itm_icon ' . $T('text', '100') . ' NULL, itm_language ' . $T('text', '25') . ' NULL, '
			. 'itm_parent_id ' . $T('id') . ' NULL, itm_active ' . $T('text', '1') . " NULL DEFAULT '1', itm_created_on " . $T('datetime') . ' NULL, '
			. 'itm_updated_on ' . $T('datetime') . ' NULL, itm_access_level ' . $T('text', '2000') . ' NULL, itm_json ' . $T('longtext') . ' NULL)'
			. ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
	}

	/** @return array{0: ?string, 1: array} [id, errors] */
	public static function save(array $in, string $id = ''): array
	{
		$db = nu_db();
		$row = [];
		$errors = [];
		foreach (self::FIELDS as $k => $len) {
			$v = $in[$k] ?? null;
			$v = $v === null ? '' : (string)$v;
			if ($k !== 'itm_description' && $k !== 'itm_additional1' && $k !== 'itm_additional2' && $k !== 'itm_notes') $v = trim($v);
			if ($len > 0) nu_maxlen($errors, $k, $v, $len);
			$row[$k] = $v === '' ? null : $v;
		}
		if ($row['itm_value'] === null) $errors['itm_value'] = t('err_required');
		if ($row['itm_sort_order'] !== null && !preg_match('/^-?\d{1,9}$/', $row['itm_sort_order'])) $errors['itm_sort_order'] = t('item_err_sort');
		$row['itm_sort_order'] = (int)($row['itm_sort_order'] ?? 0);
		$row['itm_active'] = $row['itm_active'] === '0' ? '0' : '1';
		if ($row['itm_object_id'] !== null && !$db->value('SELECT COUNT(*) FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [$row['itm_object_id']])) {
			$errors['itm_object_id'] = t('item_err_object');
		}
		if ($errors) return [null, $errors];

		// nuBuilder (nuitem_AS): nuBuilder's own items ("nu…") keep no timestamps
		$now = $db->type === 'sqlsrv' ? date('Y-m-d\TH:i:s') : date('Y-m-d H:i:s');
		$cols = $db->columns('zzzzsys_item');
		if ($id !== '') {
			if (!str_starts_with($id, 'nu') && isset($cols['itm_updated_on'])) $row['itm_updated_on'] = $now;
			$db->update('zzzzsys_item', array_intersect_key($row, $cols), 'zzzzsys_item_id', $id);
		} else {
			$id = nu_id();
			if (isset($cols['itm_created_on'])) $row['itm_created_on'] = $now;
			if (isset($cols['itm_updated_on'])) $row['itm_updated_on'] = $now;
			$db->insert('zzzzsys_item', ['zzzzsys_item_id' => $id] + array_intersect_key($row, $cols));
		}
		return [$id, []];
	}
}
