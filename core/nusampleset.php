<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Further sample applications (0.8.679): a sample set described in install/samples/<key>.json instead of PHP code – the first is the
 * ticket system (key "ticket", prefix tkt_). The JSON is written by dev/sample_capture.php from an application built with design
 * mode on a test installation, so a sample can be changed in design mode and captured again.
 *
 *   {key, version, prefix, home (form id), date_ref (Y-m-d), tables {name: {columns: [{name, type, length}], audit}},
 *    indexes {table: [column, …]}, rows [[table, row], …], users [row without password, …]}
 *
 * Every row carries its own id (all start with the prefix or are listed in the JSON), so removing deletes exactly these rows. Date and
 * date-time columns of the sample's own tables are moved by the days between date_ref and today, so dashboards show recent figures.
 * The sales sample (core/nusample.php, NuSample) stays as it is.
 */
final class NuSampleSet
{
	/** Sample sets besides NuSample: key => JSON file below install/samples/. */
	public const SETS = ['ticket' => 'ticket.json'];

	/** System tables and their primary keys, children first (removal order). */
	private const PK = [
		'zzzzsys_nunext_object_access' => 'zzzzsys_nunext_object_access_id', 'zzzzsys_nunext_widget' => 'zzzzsys_nunext_widget_id',
		'zzzzsys_nunext_dashboard' => 'zzzzsys_nunext_dashboard_id', 'zzzzsys_nunext_list_view' => 'zzzzsys_nunext_list_view_id',
		'zzzzsys_user_permission' => 'zzzzsys_user_permission_id', 'zzzzsys_permission_item' => 'zzzzsys_permission_item_id',
		'zzzzsys_event' => 'zzzzsys_event_id', 'zzzzsys_object' => 'zzzzsys_object_id', 'zzzzsys_browse' => 'zzzzsys_browse_id',
		'zzzzsys_tab' => 'zzzzsys_tab_id', 'zzzzsys_access_form' => 'zzzzsys_access_form_id', 'zzzzsys_access_php' => 'zzzzsys_access_php_id',
		'zzzzsys_access_report' => 'zzzzsys_access_report_id', 'zzzzsys_report' => 'zzzzsys_report_id', 'zzzzsys_select_clause' => 'zzzzsys_select_clause_id',
		'zzzzsys_select' => 'zzzzsys_select_id', 'zzzzsys_user' => 'zzzzsys_user_id', 'zzzzsys_access' => 'zzzzsys_access_id', 'zzzzsys_php' => 'zzzzsys_php_id',
		'zzzzsys_email_template' => 'zzzzsys_email_template_id', 'zzzzsys_file' => 'zzzzsys_file_id', 'zzzzsys_format' => 'zzzzsys_format_id',
		'zzzzsys_form' => 'zzzzsys_form_id',
	];

	/** @return list<string> */
	public static function keys(): array
	{
		return array_keys(self::SETS);
	}

	/** The sample definition (cached per request). @return array<string, mixed> */
	public static function definition(string $key): array
	{
		static $cache = [];
		if (isset($cache[$key])) return $cache[$key];
		$file = isset(self::SETS[$key]) ? NU_ROOT . '/install/samples/' . self::SETS[$key] : '';
		$j = $file !== '' && is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
		if (!is_array($j) || ($j['key'] ?? '') !== $key) throw new RuntimeException(t('smp_err_definition', $key));
		return $cache[$key] = $j;
	}

	/** Version of a sample set inside an update package (0 = not found). */
	public static function versionInZip(string $zipFile, string $prefix, string $key): int
	{
		if (!class_exists('ZipArchive') || !isset(self::SETS[$key])) return 0;
		$z = new ZipArchive();
		if ($z->open($zipFile) !== true) return 0;
		$src = $z->getFromName($prefix . 'install/samples/' . self::SETS[$key]);
		$z->close();
		$j = is_string($src) ? json_decode($src, true) : null;
		return is_array($j) ? (int)($j['version'] ?? 0) : 0;
	}

	/** @return array{installed: bool, version: int, current: int, when: string, counts: array<string, int>, tables: list<string>, leftovers: bool, blocking: list<string>} */
	public static function status(string $key): array
	{
		$d = self::definition($key);
		$db = nu_db();
		$form = $db->tableExists('zzzzsys_form') ? $db->one('SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [(string)$d['home']]) : null;
		$info = $form ? ((json_decode((string)$form['sfo_json'], true) ?: [])['nunext']['sample'] ?? []) : [];
		$ids = self::ids($d);
		$counts = [];
		foreach (['zzzzsys_user' => 'users', 'zzzzsys_access' => 'access', 'zzzzsys_form' => 'forms', 'zzzzsys_object' => 'objects', 'zzzzsys_php' => 'php', 'zzzzsys_email_template' => 'templates', 'zzzzsys_nunext_dashboard' => 'dashboards'] as $t => $k) {
			$counts[$k] = 0;
			$list = $ids[$t] ?? [];
			if ($t === 'zzzzsys_user') $list = array_column($d['users'] ?? [], 'zzzzsys_user_id');
			if ($list && $db->tableExists($t)) $counts[$k] = (int)$db->value('SELECT COUNT(*) FROM ' . $t . ' WHERE ' . self::PK[$t] . ' IN (' . self::marks($list) . ')', $list);
		}
		$tables = array_values(array_filter(array_keys($d['tables'] ?? []), static fn($t) => $db->tableExists((string)$t)));
		return [
			'installed' => (bool)$form, 'version' => (int)($info['version'] ?? 0), 'current' => (int)$d['version'],
			'when' => (string)($info['installed'] ?? ''), 'counts' => $counts, 'tables' => $tables,
			'leftovers' => !$form && (array_sum($counts) > 0 || $tables),
			'blocking' => self::blockingUsers($key),
		];
	}

	/** Users that are not part of the sample but have one of its access levels. @return list<string> */
	public static function blockingUsers(string $key): array
	{
		$d = self::definition($key);
		$levels = self::ids($d)['zzzzsys_access'] ?? [];
		$users = array_column($d['users'] ?? [], 'zzzzsys_user_id');
		if (!$levels) return [];
		$db = nu_db();
		$args = $levels;
		$sql = 'SELECT sus_login_name FROM zzzzsys_user WHERE sus_zzzzsys_access_id IN (' . self::marks($levels) . ')';
		if ($users) { $sql .= ' AND zzzzsys_user_id NOT IN (' . self::marks($users) . ')'; $args = array_merge($args, $users); }
		return array_column($db->all($sql . ' ORDER BY sus_login_name', $args), 'sus_login_name');
	}

	/** Installs (or refreshes) the sample set. @return array<string, string> login => password (random, shown once) */
	public static function install(string $key): array
	{
		nu_time_limit(300);
		$d = self::definition($key);
		$db = nu_db();
		$st = self::status($key);
		if ($st['installed'] || $st['leftovers']) self::remove($key, true);
		foreach (array_keys($d['tables'] ?? []) as $t) if ($db->tableExists((string)$t)) throw new RuntimeException(t('smp_err_table_exists', $t));
		NuFile::ensureTable();
		NuFormat::ensureTable();
		NuPermission::ensureTables();
		NuReport::ensureTables();
		NuSchema::create();
		$created = [];
		$passwords = [];
		try {
			foreach ($d['tables'] as $name => $spec) {
				NuDDL::createTable((string)$name, $name . '_id', $spec['columns'], !empty($spec['audit']));
				$created[] = (string)$name;
			}
			foreach ($d['indexes'] ?? [] as $t => $cols) foreach ($cols as $c) { try { NuDDL::ensureIndex((string)$t, (string)$c); } catch (Throwable $e) { error_log('nuBuilder Next sample index: ' . $e->getMessage()); } }
			$shift = self::shiftDays($d);
			$dates = self::dateColumns($d);
			$db->transaction(static function (NuDB $db) use ($d, $shift, $dates, &$passwords) {
				foreach ($d['rows'] as [$table, $row]) {
					if ($table === 'zzzzsys_form' && (string)$row['zzzzsys_form_id'] === (string)$d['home']) {
						$j = json_decode((string)($row['sfo_json'] ?? ''), true) ?: [];
						$j['nunext']['sample'] = ['version' => (int)$d['version'], 'installed' => date('Y-m-d H:i'), 'key' => $d['key']];
						$row['sfo_json'] = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					}
					if ($shift && isset($dates[$table])) $row = self::shift($row, $dates[$table], $shift);
					self::insert($db, (string)$table, $row);
				}
				foreach ($d['users'] ?? [] as $u) {
					$pw = self::password();
					$passwords[(string)$u['sus_login_name']] = $pw;
					self::insert($db, 'zzzzsys_user', $u + ['sus_login_password' => NuAuth::hash($pw)]);
				}
			});
		} catch (Throwable $e) {
			foreach (array_reverse($created) as $t) { try { NuDDL::dropTable($t); } catch (Throwable) {} }
			try { self::deleteRows($d); } catch (Throwable) {}
			throw $e;
		}
		NuLog::write('sample_installed', 'sample', $key, ['version' => (int)$d['version']]);
		return $passwords;
	}

	public static function remove(string $key, bool $forRefresh = false): void
	{
		$d = self::definition($key);
		if (!$forRefresh && ($b = self::blockingUsers($key))) throw new RuntimeException(t('smp_err_blocking', implode(', ', $b)));
		$known = self::status($key);
		self::deleteRows($d);
		$db = nu_db();
		if ($known['installed'] || $known['leftovers']) {
			foreach (array_reverse(array_keys($d['tables'] ?? [])) as $t) {
				if ($db->tableExists((string)$t) && isset($db->columns((string)$t)[$t . '_id'])) NuDDL::dropTable((string)$t);
			}
		}
		if (!$forRefresh) NuLog::write('sample_removed', 'sample', $key);
	}

	/** New random passwords for the users of the sample. @return array<string, string> */
	public static function newPasswords(string $key): array
	{
		$d = self::definition($key);
		$db = nu_db();
		$out = [];
		foreach ($d['users'] ?? [] as $u) {
			$id = (string)$u['zzzzsys_user_id'];
			if (!$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$id])) continue;
			$pw = self::password();
			$db->update('zzzzsys_user', ['sus_login_password' => NuAuth::hash($pw)], 'zzzzsys_user_id', $id);
			$out[(string)$u['sus_login_name']] = $pw;
		}
		return $out;
	}

	// ================================================================== helpers

	/** Ids of the sample's rows per system table. @return array<string, list<string>> */
	private static function ids(array $d): array
	{
		$out = [];
		foreach ($d['rows'] ?? [] as [$table, $row]) {
			if (!isset(self::PK[$table])) continue;
			$v = $row[self::PK[$table]] ?? null;
			if ($v !== null && $v !== '') $out[$table][] = (string)$v;
		}
		return $out;
	}

	private static function deleteRows(array $d): void
	{
		$db = nu_db();
		$ids = self::ids($d);
		$users = array_column($d['users'] ?? [], 'zzzzsys_user_id');
		$forms = $ids['zzzzsys_form'] ?? [];
		$levels = $ids['zzzzsys_access'] ?? [];
		$items = $ids['zzzzsys_permission_item'] ?? [];
		$db->transaction(static function (NuDB $db) use ($ids, $users, $forms, $levels, $items) {
			// rights of other access levels on the sample's forms / procedures, assignments of its items and users, start pages of its levels
			if ($forms && $db->tableExists('zzzzsys_access_form')) $db->run('DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id IN (' . self::marks($forms) . ')', $forms);
			if ($procs = $ids['zzzzsys_php'] ?? []) if ($db->tableExists('zzzzsys_access_php')) $db->run('DELETE FROM zzzzsys_access_php WHERE slp_zzzzsys_php_id IN (' . self::marks($procs) . ')', $procs);
			if ($reps = $ids['zzzzsys_report'] ?? []) if ($db->tableExists('zzzzsys_access_report')) $db->run('DELETE FROM zzzzsys_access_report WHERE sre_zzzzsys_report_id IN (' . self::marks($reps) . ')', $reps);
			if ($db->tableExists('zzzzsys_user_permission')) {
				if ($items) $db->run('DELETE FROM zzzzsys_user_permission WHERE upe_ident IN (' . self::marks($items) . ')', $items);
				if ($users) $db->run('DELETE FROM zzzzsys_user_permission WHERE upe_zzzzsys_user_id IN (' . self::marks($users) . ')', $users);
			}
			if ($forms && $db->tableExists('zzzzsys_nunext_object_access')) $db->run('DELETE FROM zzzzsys_nunext_object_access WHERE noa_form_id IN (' . self::marks($forms) . ')', $forms);
			foreach ($levels as $lv) {
				if ($db->tableExists('zzzzsys_nunext_list_view')) $db->run("DELETE FROM zzzzsys_nunext_list_view WHERE list_key = ? AND access_ids = ?", [NuHome::LIST_USER, json_encode([$lv])]);
			}
			if ($users) $db->run('DELETE FROM zzzzsys_user WHERE zzzzsys_user_id IN (' . self::marks($users) . ')', $users);
			foreach (array_keys(self::PK) as $t) {
				$list = $ids[$t] ?? [];
				if ($list && $db->tableExists($t)) foreach (array_chunk($list, 200) as $part) $db->run('DELETE FROM ' . $t . ' WHERE ' . self::PK[$t] . ' IN (' . self::marks($part) . ')', $part);
			}
		});
	}

	/** Days to move the sample's dates: today − date_ref. */
	private static function shiftDays(array $d): int
	{
		$ref = strtotime((string)($d['date_ref'] ?? ''));
		return $ref ? (int)floor((strtotime(date('Y-m-d')) - $ref) / 86400) : 0;
	}

	/** Date / date-time columns per own table. @return array<string, array<string, string>> table => [column => type] */
	private static function dateColumns(array $d): array
	{
		$out = [];
		foreach ($d['tables'] ?? [] as $t => $spec) {
			foreach ($spec['columns'] as $c) if (in_array($c['type'], ['date', 'datetime'], true)) $out[$t][$c['name']] = $c['type'];
			if (!empty($spec['audit'])) foreach (['created_at', 'updated_at'] as $c) $out[$t][$c] = 'datetime';
		}
		return $out;
	}

	private static function shift(array $row, array $cols, int $days): array
	{
		foreach ($cols as $c => $type) {
			if (!isset($row[$c]) || $row[$c] === '' || ($ts = strtotime((string)$row[$c])) === false) continue;
			$ts = strtotime(($days >= 0 ? '+' : '') . $days . ' days', $ts);
			$row[$c] = date($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s', (int)$ts);
		}
		return $row;
	}

	/** Insert with the columns this database has. */
	private static function insert(NuDB $db, string $table, array $row): void
	{
		static $cols = [];
		$cols[$table] ??= array_change_key_case(array_fill_keys(array_keys($db->columns($table)), true));
		$row = array_filter($row, static fn($v, $k) => $v !== null && isset($cols[$table][strtolower((string)$k)]), ARRAY_FILTER_USE_BOTH);
		foreach ($row as $k => $v) {
			if (is_bool($v)) $row[$k] = $v ? 1 : 0;
			elseif (is_array($v)) $row[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		$db->insert($table, $row);
	}

	private static function marks(array $list): string
	{
		return implode(', ', array_fill(0, count($list), '?'));
	}

	private static function password(): string
	{
		$chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$pw = '';
		for ($i = 0; $i < 14; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
		return substr($pw, 0, 7) . '-' . substr($pw, 7);
	}
}
