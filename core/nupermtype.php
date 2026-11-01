<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Types of permission items (0.8.773). nuBuilder Forte's zzzzsys_permission_item stays as it is (no new columns): the type and its settings
 * are a row of zzzzsys_nunext_permission_type with the item's id as key. No row = "plain" – the item as before (nuUserHasPermission,
 * #USER_PERMISSIONS#). nuBuilder Forte sees every item as a plain item; a row whose item is gone is removed by the housekeeping.
 *
 * Types are a registry (TYPES): a type has its settings (clean()) and a short summary (summary()). Today:
 *   plain  – a flag for code, as in nuBuilder Forte
 *   import – Data import into chosen tables: {tables: [names], add: bool, update: bool} (NuImport::itemRules)
 */
final class NuPermType
{
	public const TABLE = 'zzzzsys_nunext_permission_type';
	/** type => [label text key, Bootstrap icon] */
	public const TYPES = ['plain' => ['ptype_plain', 'bi-key'], 'import' => ['ptype_import', 'bi-file-earmark-arrow-up']];

	private static ?array $cache = null;

	/** @return array<string, array{type: string, settings: array}> item id => type and settings (only items with a type other than plain) */
	public static function all(): array
	{
		if (self::$cache !== null) return self::$cache;
		$out = [];
		try {
			if (nu_db()->tableExists(self::TABLE)) {
				foreach (nu_db()->all('SELECT zzzzsys_nunext_permission_type_id AS id, type, settings FROM ' . self::TABLE) as $r) {
					$type = (string)$r['type'];
					if (!isset(self::TYPES[$type]) || $type === 'plain') continue;
					$s = json_decode((string)$r['settings'], true);
					$out[(string)$r['id']] = ['type' => $type, 'settings' => self::clean($type, is_array($s) ? $s : [])];
				}
			}
		} catch (Throwable) { /* no table yet */ }
		return self::$cache = $out;
	}

	/** @return array{type: string, settings: array} */
	public static function of(string $itemId): array
	{
		return self::all()[$itemId] ?? ['type' => 'plain', 'settings' => []];
	}

	/** Only known keys and values of a type's settings. */
	public static function clean(string $type, array $in): array
	{
		if ($type !== 'import') return [];
		$known = array_flip(NuImport::tables());
		$tables = [];
		foreach ((array)($in['tables'] ?? []) as $t) if (is_string($t) && isset($known[$t]) && !in_array($t, $tables, true)) $tables[] = $t;
		sort($tables);
		return ['tables' => $tables, 'add' => !empty($in['add']), 'update' => !empty($in['update'])];
	}

	/** Problems of the settings before saving: [field => text]. */
	public static function errors(string $type, array $settings): array
	{
		if ($type !== 'import') return [];
		$e = [];
		if (!$settings['tables']) $e['ptype_tables'] = t('ptype_err_tables');
		if (!$settings['add'] && !$settings['update']) $e['ptype_modes'] = t('ptype_err_modes');
		return $e;
	}

	/** Stores the type of an item ('plain' removes the row). */
	public static function set(string $itemId, string $type, array $settings): void
	{
		if (!isset(self::TYPES[$type])) $type = 'plain';
		$db = nu_db();
		NuSchema::ensure();
		if ($type === 'plain') {
			if ($db->tableExists(self::TABLE)) $db->run('DELETE FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_permission_type_id = ?', [$itemId]);
		} else {
			$row = ['type' => $type, 'settings' => (string)json_encode(self::clean($type, $settings), JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
				'updated_by' => mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50)];
			if ($db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_permission_type_id = ?', [$itemId])) $db->update(self::TABLE, $row, 'zzzzsys_nunext_permission_type_id', $itemId);
			else $db->insert(self::TABLE, ['zzzzsys_nunext_permission_type_id' => $itemId] + $row);
		}
		self::$cache = null;
	}

	public static function delete(string $itemId): void
	{
		try { if (nu_db()->tableExists(self::TABLE)) nu_db()->run('DELETE FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_permission_type_id = ?', [$itemId]); } catch (Throwable) {}
		self::$cache = null;
	}

	/** "Data import: orders, order_items · add + update" – for lists and the user form. */
	public static function summary(string $type, array $settings): string
	{
		if ($type !== 'import') return '';
		$modes = array_filter([$settings['add'] ? t('ptype_add') : '', $settings['update'] ? t('ptype_update') : '']);
		return t('ptype_import') . ': ' . implode(', ', $settings['tables']) . ($modes ? ' · ' . implode(' + ', $modes) : '');
	}

	/**
	 * Active items of a type the user has: [[id, ident, settings], …] (expired users have no session, so their rows do not matter).
	 * @return list<array{0: string, 1: string, 2: array}>
	 */
	public static function forUser(string $userId, string $type): array
	{
		$typed = array_filter(self::all(), static fn($x) => $x['type'] === $type);
		if ($userId === '' || !$typed || !NuPermission::exists()) return [];
		$active = NuPermission::forUser($userId);
		$idents = array_column(NuPermission::items(), 'pme_ident', 'zzzzsys_permission_item_id');
		$out = [];
		foreach ($typed as $id => $x) if (($active[$id] ?? '0') === '1' && isset($idents[$id])) $out[] = [(string)$id, (string)$idents[$id], $x['settings']];
		return $out;
	}

	/** Housekeeping: rows whose permission item was deleted (also in nuBuilder Forte). @return int rows removed */
	public static function purgeOrphans(): int
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE) || !NuPermission::exists()) return 0;
		$ids = array_flip(array_column($db->all('SELECT zzzzsys_permission_item_id FROM ' . NuPermission::ITEM), 'zzzzsys_permission_item_id'));
		$n = 0;
		foreach ($db->all('SELECT zzzzsys_nunext_permission_type_id AS id FROM ' . self::TABLE) as $r) {
			if (!isset($ids[(string)$r['id']])) { $db->run('DELETE FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_permission_type_id = ?', [(string)$r['id']]); $n++; }
		}
		self::$cache = null;
		return $n;
	}
}
