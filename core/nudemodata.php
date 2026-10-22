<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Demo data reset (0.8.341): visitors of a public demo may save in the forms of $nuConfigDemoSavingAllowedIds – this puts the tables of
 * those forms (and of their sub-forms) back to a snapshot the globeadmin took (System status → Demo mode).
 *   Snapshot: <backup folder>/demo-snapshot.json (protected folder) – rows per table; binary values as {"b64": …}.
 *   Reset:    every N hours by the housekeeping (cron.php or now and then a page view; setting 'demo_reset' {hours, last}), or "Reset now".
 *             Each table is emptied and filled again; nothing happens without a snapshot.
 */
final class NuDemoData
{
	private const SETTING = 'demo_reset';
	public const HOURS = [0, 6, 12, 24, 48, 168];          // 0 = never automatically
	private const MAX_ROWS = 50000;
	private const MAX_BYTES = 20 * 1024 * 1024;

	public static function file(): string
	{
		return NuUpdate::backupDir() . '/demo-snapshot.json';
	}

	/** Tables of the allowed forms and their sub-forms (existing, never nuBuilder's / nuBuilder Next's own). */
	public static function tables(): array
	{
		$cfg = trim((string)(nu_cfg('nuConfigDemoSavingAllowedIds') ?? ''));
		if ($cfg === '') return [];
		$db = nu_db();
		$todo = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $cfg) ?: []), static fn($n) => $n !== ''));
		$seen = [];
		$tables = [];
		while ($todo && count($seen) < 200) {
			$ref = array_shift($todo);
			$f = $db->one('SELECT zzzzsys_form_id, sfo_table FROM zzzzsys_form WHERE zzzzsys_form_id = ? OR sfo_code = ?', [$ref, $ref]);
			if (!$f || isset($seen[$f['zzzzsys_form_id']])) continue;
			$seen[$f['zzzzsys_form_id']] = true;
			$t = trim((string)$f['sfo_table']);
			if ($t !== '' && !str_starts_with(strtolower($t), 'zzzzsys_') && $db->tableExists($t)) $tables[$t] = true;
			foreach ($db->all("SELECT sob_subform_zzzzsys_form_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_type = 'subform'", [$f['zzzzsys_form_id']]) as $o) {
				if ((string)$o['sob_subform_zzzzsys_form_id'] !== '') $todo[] = (string)$o['sob_subform_zzzzsys_form_id'];
			}
		}
		return array_keys($tables);
	}

	/** Snapshot info: ['created' => time, 'by' => login, 'tables' => [table => rows]] or null. */
	public static function info(): ?array
	{
		$f = self::file();
		if (!is_file($f)) return null;
		$h = @fopen($f, 'r');
		$head = $h ? (string)fread($h, 4096) : '';
		if ($h) fclose($h);
		// the header line holds the summary, so the (big) data need not be read for the status page
		$line = strtok($head, "\n");
		$d = json_decode((string)$line, true);
		return is_array($d) && isset($d['created']) ? $d : null;
	}

	/** Takes the snapshot. @return array the info; throws RuntimeException */
	public static function snapshot(string $by): array
	{
		$tables = self::tables();
		if (!$tables) throw new RuntimeException(t('demo_snap_no_tables'));
		$db = nu_db();
		$data = [];
		$count = [];
		$rows = 0;
		foreach ($tables as $t) {
			$list = [];
			foreach ($db->all('SELECT * FROM ' . NuDB::ident($t)) as $r) {
				foreach ($r as $k => $v) if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) $r[$k] = ['b64' => base64_encode($v)];
				$list[] = $r;
				if (++$rows > self::MAX_ROWS) throw new RuntimeException(t('demo_snap_too_big', self::MAX_ROWS));
			}
			$data[$t] = $list;
			$count[$t] = count($list);
		}
		$info = ['created' => time(), 'by' => $by, 'tables' => $count];
		$json = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if (strlen($json) > self::MAX_BYTES) throw new RuntimeException(t('demo_snap_too_big', self::MAX_ROWS));
		NuUpdate::backupDir();
		if (@file_put_contents(self::file(), $json, LOCK_EX) === false) throw new RuntimeException(t('demo_snap_write'));
		NuLog::write('demo_snapshot', '', '', ['tables' => $count]);
		return $info;
	}

	/** Puts the tables back to the snapshot. @return int rows written; throws RuntimeException */
	public static function reset(string $by): int
	{
		$f = self::file();
		if (!is_file($f)) throw new RuntimeException(t('demo_snap_none'));
		[$head, $body] = array_pad(explode("\n", (string)file_get_contents($f), 2), 2, '');
		$data = json_decode($body, true);
		if (!is_array($data)) throw new RuntimeException(t('demo_snap_none'));
		$db = nu_db();
		$n = 0;
		NuDemo::permitted(static function () use ($db, $data, &$n): void {        // also when the housekeeping runs in a demo visitor's request
			$run = static function () use ($db, $data, &$n): void {
				foreach (array_reverse(array_keys($data)) as $t) if ($db->tableExists((string)$t)) $db->run('DELETE FROM ' . NuDB::ident((string)$t));
				foreach ($data as $t => $rows) {
					if (!$db->tableExists((string)$t)) continue;
					foreach ((array)$rows as $r) {
						foreach ($r as $k => $v) if (is_array($v) && isset($v['b64'])) $r[$k] = base64_decode((string)$v['b64']);
						$db->insert((string)$t, $r);
						$n++;
					}
				}
			};
			try { $db->transaction(static fn() => $run()); }
			catch (PDOException $e) { if (str_contains($e->getMessage(), 'transaction')) $run(); else throw $e; }
		});
		self::saveSetting(['last' => time()] + self::setting());
		NuLog::write('demo_reset', '', '', ['rows' => $n, 'by' => $by]);
		return $n;
	}

	public static function setting(): array
	{
		try {
			$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
			$d = is_string($v) ? json_decode($v, true) : null;
		} catch (Throwable) {
			$d = null;
		}
		return (is_array($d) ? $d : []) + ['hours' => 24, 'last' => 0];
	}

	public static function saveSetting(array $s): void
	{
		$db = nu_db();
		NuSchema::ensure();
		$row = ['setting_value' => json_encode(['hours' => (int)$s['hours'], 'last' => (int)$s['last']]), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => (string)(NuAuth::user()['login'] ?? 'housekeeping')];
		NuDemo::permitted(static function () use ($db, $row): void {
			if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
			else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
		});
	}

	/** Housekeeping: reset when demo mode is on, a snapshot exists and the interval has passed. @return int rows written */
	public static function resetIfDue(): int
	{
		if (!NuDemo::configured() || !is_file(self::file())) return 0;
		$s = self::setting();
		if ((int)$s['hours'] <= 0 || time() - (int)$s['last'] < (int)$s['hours'] * 3600) return 0;
		$info = self::info();
		if ($info && (int)$s['last'] === 0 && time() - (int)$info['created'] < (int)$s['hours'] * 3600) return 0;   // first period counts from the snapshot
		return self::reset('housekeeping');
	}
}
