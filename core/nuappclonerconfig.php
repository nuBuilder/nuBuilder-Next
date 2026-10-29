<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Saved App cloner configurations (0.8.276) – nuBuilder's table zzzzsys_app_cloner, the same rows nuBuilder's App cloner form
 * ("nuappcloner") reads and writes, so a configuration saved in one is opened in the other.
 *
 * Mapping (nuBuilder column → App cloner page):
 *   cla_code, cla_description                 code, description
 *   cla_target_host / _port / _username / _db host, port, user, db (with "same login": the values of nuconfig.php)
 *   cla_target_charset, cla_target_db_collation charset, collation
 *   cla_target_password                        never written here (the page asks for it per run); a password nuBuilder saved
 *                                              is used when the field is left empty and kept on save
 *   cla_target_db_mode  create|fail|clear      add|fail|clear ("Create if not exists" = add to an existing database)
 *   cla_opt_1 … cla_opt_9                      parts sys_struct, user_struct, sys_rows, app_rows, user_rows, functions,
 *                                              procedures, triggers, events
 *   cla_insert_type                            insert
 *   cla_opt_2_include  JSON list (nuBuilder's multi select) – tables; empty = all
 *   cla_copy_files, cla_source_path, cla_target_path, cla_copy_files_mode create|fail|clear (create = merge)
 *   cla_excluded_dirs / cla_excluded_files     comma separated (nuBuilder) ↔ one per line; saved into cla_excluded_dirs
 *   cla_dry_run, cla_show_progress, cla_transaction_mode, cla_log_file  only nuBuilder's – kept as they are
 * Not in the table (page defaults on open): "create the database", "leave out sessions".
 */
final class NuAppClonerConfig
{
	public const TABLE = 'zzzzsys_app_cloner';
	/** nuBuilder's column lengths (nubuilder4.sql) */
	public const LEN = ['cla_code' => 100, 'cla_description' => 100, 'cla_target_host' => 50, 'cla_target_db' => 40, 'cla_target_username' => 50,
		'cla_target_charset' => 20, 'cla_target_db_collation' => 30, 'cla_source_path' => 400, 'cla_target_path' => 400, 'cla_excluded_dirs' => 2000];
	/** page field => column (length checks) */
	private const FIELD = ['code' => 'cla_code', 'description' => 'cla_description', 'host' => 'cla_target_host', 'db' => 'cla_target_db', 'user' => 'cla_target_username',
		'charset' => 'cla_target_charset', 'collation' => 'cla_target_db_collation', 'source_path' => 'cla_source_path', 'target_path' => 'cla_target_path', 'excludes' => 'cla_excluded_dirs'];
	private const PARTS = ['sys_struct', 'user_struct', 'sys_rows', 'app_rows', 'user_rows', 'functions', 'procedures', 'triggers', 'events'];

	/** Creates the table with nuBuilder's structure when it is missing (installer, update step 0004, first use). */
	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists(self::TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$c = ['zzzzsys_app_cloner_id ' . $T('id') . ' NOT NULL PRIMARY KEY'];
		foreach (['cla_code' => 100, 'cla_description' => 100, 'cla_log_file' => 150, 'cla_copy_files' => 1, 'cla_copy_files_mode' => 10, 'cla_source_path' => 400,
			'cla_target_host' => 50, 'cla_target_db' => 40, 'cla_target_username' => 50, 'cla_target_password' => 50] as $k => $n) $c[] = "$k " . $T('text', (string)$n) . ' NULL';
		$c[] = 'cla_target_port ' . ($db->type === 'mysql' ? 'SMALLINT' : $T('int')) . ' NULL';
		foreach (['cla_target_db_collation' => 30, 'cla_target_charset' => 20, 'cla_target_path' => 400, 'cla_excluded_dirs' => 2000, 'cla_excluded_files' => 2000,
			'cla_insert_type' => 15, 'cla_target_db_mode' => 10, 'cla_dry_run' => 1, 'cla_opt_1' => 1, 'cla_opt_2' => 1] as $k => $n) $c[] = "$k " . $T('text', (string)$n) . ' NULL';
		$c[] = 'cla_opt_2_include ' . $T('longtext') . ' NULL';
		foreach (['cla_opt_3', 'cla_opt_4', 'cla_opt_5', 'cla_opt_6', 'cla_opt_7', 'cla_opt_8', 'cla_opt_9', 'cla_show_progress', 'cla_transaction_mode'] as $k) $c[] = "$k " . $T('text', '1') . ' NULL';
		$db->run('CREATE TABLE ' . self::TABLE . ' (' . implode(', ', $c) . ')' . ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
		NuDB::forgetColumns(self::TABLE);
	}

	/** @return array<int, array<string, mixed>> */
	public static function all(string $q = ''): array
	{
		$where = ''; $p = [];
		if ($q !== '') { $where = " WHERE (cla_code LIKE ? ESCAPE '!' OR cla_description LIKE ? ESCAPE '!' OR cla_target_db LIKE ? ESCAPE '!' OR cla_target_path LIKE ? ESCAPE '!')"; $p = array_fill(0, 4, NuDB::like($q)); }
		return nu_db()->all('SELECT * FROM ' . self::TABLE . $where . ' ORDER BY cla_code, cla_description', $p);
	}

	public static function get(string $id): ?array
	{
		return $id === '' ? null : nu_db()->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_app_cloner_id = ?', [$id]);
	}

	/**
	 * The page's input array from a row. $def = the page's defaults (same keys).
	 * @param array<string, mixed> $row @param array<string, mixed> $def @param array<string, mixed> $cfg nu_config()
	 */
	public static function toInput(array $row, array $def, array $cfg, int $defPort): array
	{
		$s = static fn(string $k) => trim((string)($row[$k] ?? ''));
		$in = $def;
		$in['code'] = $s('cla_code');
		$in['description'] = $s('cla_description');
		foreach (['host' => 'cla_target_host', 'user' => 'cla_target_username', 'db' => 'cla_target_db', 'charset' => 'cla_target_charset',
			'collation' => 'cla_target_db_collation', 'source_path' => 'cla_source_path', 'target_path' => 'cla_target_path'] as $k => $col) if ($s($col) !== '') $in[$k] = $s($col);
		$in['port'] = $s('cla_target_port') !== '' ? $s('cla_target_port') : (string)$defPort;
		$in['same_login'] = $s('cla_target_password') === '' && strcasecmp($in['host'], (string)$cfg['nuConfigDBHost']) === 0
			&& $in['user'] === (string)$cfg['nuConfigDBUser'] && (int)$in['port'] === (int)($cfg['nuConfigDBPort'] ?: $defPort);
		$in['db_mode'] = ['create' => 'add', 'fail' => 'fail', 'clear' => 'clear'][$s('cla_target_db_mode')] ?? $def['db_mode'];
		$parts = [];
		foreach (self::PARTS as $i => $p) if ($s('cla_opt_' . ($i + 1)) === '1') $parts[] = $p;
		$in['parts'] = $parts;
		if (in_array($s('cla_insert_type'), ['INSERT', 'INSERT IGNORE', 'REPLACE'], true)) $in['insert'] = $s('cla_insert_type');
		$tables = json_decode($s('cla_opt_2_include'), true);
		$tables = is_array($tables) ? array_values(array_filter(array_map('strval', $tables), static fn($s) => (string)$s !== '')) : [];
		$in['tables_all'] = !$tables;
		$in['tables'] = $tables;
		$in['files'] = $s('cla_copy_files') === '1';
		$in['file_mode'] = ['create' => 'merge', 'fail' => 'fail', 'clear' => 'clear'][$s('cla_copy_files_mode')] ?? $def['file_mode'];
		$ex = [];
		foreach (['cla_excluded_dirs', 'cla_excluded_files'] as $col) foreach (preg_split('/[,\r\n]+/', $s($col)) ?: [] as $e) if (trim($e) !== '') $ex[] = trim($e);
		if ($s('cla_excluded_dirs') !== '' || $s('cla_excluded_files') !== '') $in['excludes'] = implode("\n", array_values(array_unique($ex)));
		$in['stored_password'] = $s('cla_target_password') !== '';
		return $in;
	}

	/**
	 * Saves the page's input; $id '' = new row – with $from (a clone, 0.8.680) its other columns (nuBuilder's password, log file …)
	 * come from that row. @return array{0: ?string, 1: array<string, string>} [id, errors]
	 * @param array<string, mixed> $in
	 */
	public static function save(array $in, string $id = '', ?array $from = null): array
	{
		$db = nu_db();
		$row = [
			'cla_code' => trim((string)$in['code']), 'cla_description' => trim((string)$in['description']),
			'cla_target_host' => (string)$in['host'], 'cla_target_port' => (int)$in['port'] ?: null, 'cla_target_username' => (string)$in['user'],
			'cla_target_db' => (string)$in['db'], 'cla_target_charset' => (string)$in['charset'], 'cla_target_db_collation' => (string)$in['collation'],
			'cla_target_db_mode' => ['add' => 'create', 'fail' => 'fail', 'clear' => 'clear'][$in['db_mode']] ?? 'fail',
			'cla_insert_type' => (string)$in['insert'],
			'cla_opt_2_include' => $in['tables_all'] ? '' : json_encode(array_values($in['tables']), JSON_UNESCAPED_UNICODE),
			'cla_copy_files' => $in['files'] ? '1' : '0', 'cla_source_path' => (string)$in['source_path'], 'cla_target_path' => (string)$in['target_path'],
			'cla_copy_files_mode' => ['merge' => 'create', 'fail' => 'fail', 'clear' => 'clear'][$in['file_mode']] ?? 'fail',
			'cla_excluded_dirs' => implode(', ', array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$in['excludes']) ?: []), static fn($s) => (string)$s !== ''))),
		];
		foreach (self::PARTS as $i => $p) $row['cla_opt_' . ($i + 1)] = in_array($p, $in['parts'], true) ? '1' : '0';
		$errors = [];
		if ($row['cla_code'] === '') $errors['code'] = t('err_required');
		foreach (self::FIELD as $k => $col) if (!isset($errors[$k])) nu_maxlen($errors, $k, (string)$row[$col], self::LEN[$col]);
		if (!isset($errors['code']) && (int)$db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE cla_code = ? AND zzzzsys_app_cloner_id <> ?', [$row['cla_code'], $id])) {
			$errors['code'] = t('ac_err_code_exists');
		}
		if ($errors) return [null, $errors];
		if ($id !== '') {
			$db->update(self::TABLE, $row, 'zzzzsys_app_cloner_id', $id);              // nuBuilder's own columns (password, log file …) stay
		} else {
			$id = nu_id();
			$rest = $from ? array_diff_key($from, ['zzzzsys_app_cloner_id' => 1])
				: ['cla_excluded_files' => '', 'cla_dry_run' => '0', 'cla_show_progress' => '1', 'cla_transaction_mode' => '0'];
			$db->insert(self::TABLE, ['zzzzsys_app_cloner_id' => $id] + $row + $rest);
		}
		return [$id, []];
	}

	/** A free code for a copy: "code_copy", "code_copy_2" … */
	public static function cloneCode(string $code): string
	{
		$base = mb_substr($code, 0, 90) . '_copy';
		$n = 1;
		do { $try = $base . ($n > 1 ? '_' . $n : ''); $n++; }
		while ((int)nu_db()->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE cla_code = ?', [$try]) && $n < 100);
		return $try;
	}
}
