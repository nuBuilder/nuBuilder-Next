<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Is the database ready? (0.8.645) nuconfig.php only says how to connect – it may have been written by hand (or copied from nuBuilder
 * Forte) for a database that was never set up. Every request asks once per session (cached when ready):
 *   ok      – the tables of nuBuilder Next's install scripts exist (and, MySQL, every column of nuBuilder 4.9 in them)
 *   empty   – none of nuBuilder's core tables (zzzzsys_form / _user / _access / _setup): the database steps of the installer are offered
 *             (pages/nudbsetup.php – signed in as globeadmin, or with the setup token)
 *   partial – some of the tables are missing: "Create missing tables" (the install scripts only add what is missing)
 *   old     – a nuBuilder Forte database older than 4.9 (e.g. zzzzsys_form.sfo_status missing): explained, nothing is changed
 * index.php sends the globeadmin to ?p=dbsetup and shows everybody else a notice; the globeadmin's sign-in creates the own tables only
 * when the database is ready (NuAuth::start). An existing nuBuilder database without $nuConfigAllowExistingNuDB is still blocked first
 * (NuInstall::existingBlocked).
 */
final class NuDbState
{
	private const CORE = ['zzzzsys_form', 'zzzzsys_user', 'zzzzsys_access', 'zzzzsys_setup'];
	private static ?array $state = null;

	/** Session key of a ready database: version and connection (another database in nuconfig.php is checked again). */
	private static function key(): string
	{
		return NU_VERSION . '|' . (string)nu_cfg('nuConfigDBHost') . '|' . (string)nu_cfg('nuConfigDBName');
	}

	public static function ready(): bool
	{
		if (isset($_SESSION['nu_db_ready']) && $_SESSION['nu_db_ready'] === self::key()) return true;
		$ok = self::check()['state'] === 'ok';
		if ($ok && session_status() === PHP_SESSION_ACTIVE) $_SESSION['nu_db_ready'] = self::key();
		return $ok;
	}

	/** Forget the cached answer (after the set-up / repair). */
	public static function reset(): void
	{
		self::$state = null;
		unset($_SESSION['nu_db_ready']);
	}

	/** Tables of nuBuilder Next's install scripts for this database type. @return list<string> */
	public static function required(string $type): array
	{
		$out = [];
		foreach (NuInstall::schemaFiles('nunext', $type, null) as $f) {
			preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\[]?(?:dbo[`\]]?\.[`\[]?)?(zzzzsys_\w+)/i', (string)@file_get_contents($f), $m);
			foreach ($m[1] as $t) $out[strtolower($t)] = true;
		}
		return array_keys($out);
	}

	/** @return array{state: string, missing: list<string>, columns: list<string>, error: string} */
	public static function check(): array
	{
		if (self::$state !== null) return self::$state;
		$out = ['state' => 'ok', 'missing' => [], 'columns' => [], 'error' => ''];
		try {
			$db = nu_db();
			$have = array_map('strtolower', array_map('strval', $db->type === 'mysql'
				? array_column($db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'), 't')
				: array_column($db->all("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE IN ('BASE TABLE', 'VIEW')"), 't')));
			$out['missing'] = array_values(array_diff(self::required($db->type), $have));
			if (!array_intersect(self::CORE, $have)) $out['state'] = 'empty';
			elseif ($out['missing']) $out['state'] = 'partial';
			elseif ($db->type === 'mysql') {
				$cols = [];
				foreach ($db->all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', ['zzzzsys\_%']) as $r) {
					$cols[strtolower((string)$r['t'])][strtolower((string)$r['c'])] = true;
				}
				foreach (NuInstall::tables49() as $t => $defs) {
					if (!isset($cols[$t])) continue;
					foreach (array_keys($defs) as $c) if (!isset($cols[$t][strtolower($c)])) $out['columns'][] = $t . '.' . $c;
				}
				if ($out['columns']) $out['state'] = 'old';
			}
		} catch (Throwable $e) {
			$out['error'] = $e->getMessage();          // no connection: index.php's error handling as before
		}
		return self::$state = $out;
	}

	/**
	 * Sets up an empty database (A) or adds the missing tables (D): nuBuilder Next's install scripts (CREATE TABLE IF NOT EXISTS /
	 * INSERT IGNORE – existing tables and rows stay) or, empty MySQL database only, a nuBuilder Forte SQL dump of any 4.x (brought to
	 * 4.9); then the settings table, the own tables and the database steps – as the installer does. @return array{imported: int, upgraded: ?array, steps: array}
	 */
	public static function setUp(string $choice, ?string $dumpFile = null): array
	{
		nu_time_limit(600);
		$db = nu_db();
		$st = self::check();
		if ($st['state'] === 'ok' || $st['state'] === 'old') throw new RuntimeException(t('dbs_err_not_needed'));
		$out = ['imported' => 0, 'upgraded' => null, 'steps' => []];
		if ($choice === 'upload') {
			if ($st['state'] !== 'empty' || $db->type !== 'mysql') throw new RuntimeException(t('dbs_err_not_needed'));
			$sql = $dumpFile !== null && is_file($dumpFile) ? (string)file_get_contents($dumpFile) : '';
			$chk = $sql !== '' ? NuInstall::checkDump($sql) : ['ok' => false, 'error' => t('ins_dump_err_none')];
			if (!$chk['ok']) throw new RuntimeException($chk['error']);
			$out['imported'] = NuInstall::importSql($db, $sql, basename((string)$dumpFile));
			$out['upgraded'] = NuInstall::upgradeTo49($db);
		} else {
			foreach (NuInstall::schemaFiles('nunext', $db->type, null) as $f) $out['imported'] += NuInstall::importFile($db, $f);
		}
		try { NuSettings::create($db); } catch (Throwable $e) { error_log('nuBuilder Next database set-up, settings table: ' . $e->getMessage()); }
		$new = !NuSchema::present();
		NuSchema::allow();
		NuSchema::create();
		NuInstall::setOrigin('nunext');
		if ($new || $st['state'] === 'empty') NuSecurity::save(['record_check' => true, 'protect_fields' => true]);   // defaults of new installations
		$out['steps'] = NuMigrate::run();
		NuLog::write('db_setup', '', '', ['state' => $st['state'], 'choice' => $choice, 'statements' => $out['imported']]);
		self::reset();
		unset($_SESSION['nu_schema']);
		return $out;
	}
}
