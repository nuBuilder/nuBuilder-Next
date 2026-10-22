<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Preview of the database changes of an update (0.8.346) – update page ("Check package") and the dry run of the unattended update.
 * Sources:
 *   install/db_changes.json of the package (dev/db_changes.php, written by dev/release.py): its database steps with the tables they
 *     touch (NuMigrate::CHANGES) → the steps this database has not applied yet; its own table definitions (NuSchema) → new tables /
 *     columns of nuBuilder Next's own tables;
 *   install/schema_expected.json of the package (NuSchemaCheck::compare) → missing columns / other column types of nuBuilder's tables;
 *   the sample data refresh, when it will run.
 * Every structure change comes with the SQL for this database. Packages built before 0.8.346 have no db_changes.json ("not described").
 */
final class NuDbChanges
{
	public const FILE = 'install/db_changes.json';

	public static function parse(?string $json): ?array
	{
		$d = json_decode((string)$json, true);
		return is_array($d) && (int)($d['format'] ?? 0) === 1 && is_array($d['steps'] ?? null) && is_array($d['own_tables'] ?? null) ? $d : null;
	}

	public static function fromZip(string $zipFile, string $prefix): ?array
	{
		if (!class_exists('ZipArchive')) return null;
		$z = new ZipArchive();
		if ($z->open($zipFile) !== true) return null;
		try {
			$j = $z->getFromName($prefix . self::FILE);
			return $j === false ? null : self::parse($j);
		} finally {
			$z->close();
		}
	}

	/**
	 * @param ?array $meta      db_changes.json of the package (null: not described)
	 * @param ?array $expected  schema_expected.json of the package
	 * @param ?array $cmp       NuSchemaCheck::compare($expected)
	 * @param ?array $sample    ['from' => int, 'to' => int] when the sample data will be refreshed
	 * @return array{described: bool, steps: list<array>, items: list<array>, by_table: array<string, list<array>>, none: bool}
	 *   item: table, kind (table|column|type|data), what, sql (list of statements), step (id or ''), risky (bool)
	 */
	public static function preview(?array $meta, ?array $expected, ?array $cmp, ?array $sample = null): array
	{
		$db = nu_db();
		$items = [];
		$steps = [];
		$seen = [];
		$kept = [];
		if ($meta) {
			$applied = array_flip(NuMigrate::appliedIds());
			foreach ($meta['steps'] as $st) {
				$id = (string)($st['id'] ?? '');
				if ($id === '' || isset($applied[$id])) continue;
				$steps[] = ['id' => $id, 'description' => (string)($st['description'] ?? '')];
				foreach ((array)($st['changes'] ?? []) as $c) {
					$items[] = ['table' => (string)($c['table'] ?? ''), 'kind' => self::kind((string)($c['kind'] ?? 'data')), 'what' => (string)($c['what'] ?? ''), 'sql' => [], 'step' => $id, 'risky' => false];
				}
			}
			foreach ($meta['own_tables'] as $table => $def) {
				$table = (string)$table;
				if (!preg_match('/^zzzzsys_nunext_\w+$/', $table) || !is_array($def) || !isset($def['pk'], $def['cols'])) continue;
				try {
					if (!$db->tableExists($table)) {
						$items[] = ['table' => $table, 'kind' => 'table', 'what' => t('upd_dbc_new_table', count((array)$def['cols']) + 1), 'sql' => NuSchema::createSql($table, $def), 'step' => '', 'risky' => false];
						$seen[strtolower($table)] = true;
						continue;
					}
					foreach (NuSchema::missingColumns($table, $def) as $c) {
						$items[] = ['table' => $table, 'kind' => 'column', 'what' => t('upd_dbc_new_column', (string)$c[0], (string)$c[1] . (isset($c[2]) && $c[2] !== '' ? ' ' . $c[2] : '')),
							'sql' => [NuSchema::addColumnSql($table, $c)], 'step' => '', 'risky' => false];
						$seen[strtolower($table . '.' . $c[0])] = true;
					}
				} catch (Throwable) {
					// a definition this version cannot turn into SQL: leave it to the update itself
				}
			}
		}
		if ($cmp && $expected) {
			foreach ($cmp['missing'] as $m) {
				if (isset($seen[strtolower($m['table'])]) || isset($seen[strtolower($m['table'] . '.' . $m['column'])])) continue;
				$items[] = ['table' => $m['table'], 'kind' => 'column', 'what' => t('upd_dbc_new_column', $m['column'], $m['type']),
					'sql' => array_filter([NuSchemaCheck::sqlFor($expected, $m['key'], $m)]), 'step' => '', 'risky' => false];
			}
			foreach ($cmp['changed'] as $c) {
				// a type that could shorten data (usually: the database has a LARGER type than nuBuilder 4.9) is not changed unless the
				// globeadmin ticks it under "Database structure" – so it is no change of this update, only listed apart (0.8.348)
				if (!$c['safe']) { $kept[] = $c['table'] . '.' . $c['column'] . ': ' . $c['actual'] . ' (' . $c['expected'] . ')'; continue; }
				$items[] = ['table' => $c['table'], 'kind' => 'type', 'what' => t('upd_dbc_type', $c['column'], $c['actual'], $c['expected']),
					'sql' => array_filter([NuSchemaCheck::sqlFor($expected, $c['key'], $c)]), 'step' => '', 'risky' => false];
			}
		}
		if ($sample) $items[] = ['table' => 'sample_*', 'kind' => 'data', 'what' => t('upd_dbc_sample', (string)$sample['from'], (string)$sample['to']), 'sql' => [], 'step' => '', 'risky' => false];
		$by = [];
		foreach ($items as $it) $by[$it['table']][] = $it;
		ksort($by);
		return ['described' => $meta !== null, 'steps' => $steps, 'items' => $items, 'by_table' => $by, 'none' => !$items && !$steps, 'kept' => $kept];
	}

	private static function kind(string $k): string
	{
		return in_array($k, ['table', 'column', 'type', 'data'], true) ? $k : 'data';
	}

	/** The preview for the API / command line (dry run): plain lists, SQL included. */
	public static function report(array $p): array
	{
		return ['described' => $p['described'], 'none' => $p['none'], 'steps' => $p['steps'], 'kept' => $p['kept'],
			'tables' => array_map(static fn($list) => array_map(static fn($it) => array_filter(['kind' => $it['kind'], 'what' => $it['what'],
				'step' => $it['step'] ?: null, 'risky' => $it['risky'] ?: null, 'sql' => $it['sql'] ? array_values($it['sql']) : null], static fn($v) => $v !== null), $list), $p['by_table'])];
	}
}
