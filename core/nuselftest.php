<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Self-test (0.8.361): integration checks of the installation from inside the application – ?p=selftest, only in developer mode
 * (globeadmin + $nuConfigDevMode, NuHash::devMode()), never for demo visitors. Meant for development / test installations: the
 * form checks write records into the SAMPLE application (sample_customer, sample_order, sample_order_item) and delete them again,
 * also their audit rows; a notification to the globeadmin is created and deleted. Nothing else is changed.
 * Each check: [group, id, title, fn] → fn returns [status ok|warn|fail|skip, detail]; exceptions count as fail.
 * The browser tests of a release (dev/tests/e2e.js) run it too.
 */
final class NuSelfTest
{
	public const GROUPS = ['system', 'data', 'forms', 'rights', 'features'];
	private const MARK = 'ST-';

	/** @var array<string, mixed> ids created during a run (cleaned up at the end) */
	private static array $made = [];

	public static function allowed(): bool
	{
		return NuHash::devMode();
	}

	/**
	 * The results of the session as the FROM of the system list 'selftest' (NuListDefs): a derived table of SELECT … UNION ALL with
	 * parameters – nothing is stored and nothing created in the database. 0.8.766: before, a TEMPORARY table – which failed where the
	 * database user lacks CREATE TEMPORARY TABLES (or MySQL refuses temporary tables in a transaction under GTID consistency).
	 * @var array{0: string, 1: list<mixed>}
	 */
	public static array $source = [' FROM (SELECT 0 AS pos, \'\' AS grp, \'\' AS id, \'\' AS title, \'\' AS status, \'\' AS detail, 0 AS ms WHERE 1 = 0) s', []];

	public static function source(array $results): void
	{
		$db = nu_db();
		$int = static fn(string $p): string => $db->type === 'mysql' ? "CAST($p AS SIGNED)" : "CAST($p AS INT)";
		$txt = static fn(string $p): string => $db->type === 'sqlsrv' ? "CAST($p AS NVARCHAR(MAX))" : $p;
		$sel = [];
		$params = [];
		foreach (array_values($results) as $i => $r) {
			$sel[] = 'SELECT ' . $int('?') . ' AS pos, ' . $txt('?') . ' AS grp, ' . $txt('?') . ' AS id, ' . $txt('?') . ' AS title, ' . $txt('?') . ' AS status, ' . $txt('?') . ' AS detail, ' . $int('?') . ' AS ms';
			array_push($params, $i + 1, mb_substr((string)($r['group'] ?? ''), 0, 40), mb_substr((string)($r['id'] ?? ''), 0, 80), (string)($r['title'] ?? ''), mb_substr((string)($r['status'] ?? ''), 0, 10), (string)($r['detail'] ?? ''), (int)($r['ms'] ?? 0));
		}
		if ($sel) self::$source = [' FROM (' . implode(' UNION ALL ', $sel) . ') s', $params];
		else self::$source = [' FROM (SELECT 0 AS pos, \'\' AS grp, \'\' AS id, \'\' AS title, \'\' AS status, \'\' AS detail, 0 AS ms' . ($db->type === 'mysql' ? ' FROM DUAL' : '') . ' WHERE 1 = 0) s', []];   // no run yet: an empty list
	}

	/** @return list<array{group: string, id: string, title: string, status: string, detail: string, ms: int}> */
	public static function run(array $groups = []): array
	{
		nu_time_limit(300);
		self::$made = ['customers' => [], 'orders' => [], 'notifications' => []];
		$groups = $groups ?: self::GROUPS;
		$out = [];
		$skipForms = null;
		try {
			foreach (self::checks() as [$group, $id, $fn]) {
				if (!in_array($group, $groups, true)) continue;
				$t = hrtime(true);
				if (in_array($group, ['forms', 'rights'], true) && $id !== 'sample' && $skipForms !== null) {
					[$st, $detail] = ['skip', $skipForms];
				} else {
					try {
						[$st, $detail] = $fn();
					} catch (Throwable $e) {
						[$st, $detail] = ['fail', get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
					}
				}
				if ($id === 'sample' && $st !== 'ok') $skipForms = t('st_no_sample');
				$out[] = ['group' => $group, 'id' => $id, 'title' => t('st_' . $id), 'status' => $st, 'detail' => (string)$detail, 'ms' => (int)((hrtime(true) - $t) / 1e6)];
			}
		} finally {
			$out[] = self::cleanup();
		}
		$sum = array_count_values(array_column($out, 'status'));
		NuLog::write('selftest_run', '', '', ['ok' => $sum['ok'] ?? 0, 'warn' => $sum['warn'] ?? 0, 'fail' => $sum['fail'] ?? 0, 'skip' => $sum['skip'] ?? 0]);
		return $out;
	}

	/** @return list<array{0: string, 1: string, 2: callable}> */
	private static function checks(): array
	{
		$db = nu_db();
		$ok = static fn(string $d = '') => ['ok', $d];
		$fail = static fn(string $d) => ['fail', $d];
		$warn = static fn(string $d) => ['warn', $d];
		return [
			// ------------------------------------------------------------ system
			['system', 'php', static function () use ($ok, $warn) {
				$miss = array_values(array_filter(['pdo', 'mbstring', 'json', 'openssl', 'zip', 'gd', 'intl', 'curl'], static fn($e) => !extension_loaded($e)));
				$v = 'PHP ' . PHP_VERSION;
				$fix = NuSecurity::phpFixNote();               // 0.8.362: PHP2Shell
				$d = $v . ($miss ? ' · ' . t('st_missing', implode(', ', $miss)) : '') . ($fix !== '' ? ' · ' . $fix : '');
				return ($miss || $fix !== '') ? $warn($d) : $ok($v);
			}],
			['system', 'writable', static function () use ($ok, $fail) {
				$bad = [];
				foreach ([NU_ROOT . '/temp', NuUpdate::backupDir()] as $d) {
					if (!is_dir($d)) @mkdir($d, 0775, true);
					$f = $d . '/.selftest-' . bin2hex(random_bytes(4));
					if (@file_put_contents($f, 'x') === false) $bad[] = $d; else @unlink($f);
				}
				return $bad ? $fail(t('st_not_writable', implode(', ', $bad))) : $ok();
			}],
			['system', 'own_tables', static function () use ($db, $ok, $fail) {
				$bad = [];
				foreach (NuSchema::definitions() as $table => $def) {
					if (!$db->tableExists($table)) { $bad[] = $table; continue; }
					foreach (NuSchema::missingColumns($table, $def) as $c) $bad[] = $table . '.' . $c[0];
				}
				return $bad ? $fail(t('st_missing', implode(', ', $bad))) : $ok(t('st_n_tables', count(NuSchema::definitions())));
			}],
			['system', 'migrations', static function () use ($ok, $fail) {
				$open = array_values(array_diff(array_column(NuMigrate::catalog(), 'id'), NuMigrate::appliedIds()));
				return $open ? $fail(t('st_missing', implode(', ', $open))) : $ok(t('st_n_steps', count(NuMigrate::catalog())));
			}],
			['system', 'schema', static function () use ($ok, $warn) {
				$f = NU_ROOT . '/install/schema_expected.json';
				$exp = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
				if (!is_array($exp)) return $warn(t('st_missing', 'install/schema_expected.json'));
				$c = NuSchemaCheck::compare($exp);
				$miss = array_map(static fn($m) => $m['table'] . '.' . $m['column'], $c['missing']);
				return $miss ? $warn(t('st_missing', implode(', ', array_slice($miss, 0, 20)))) : $ok();
			}],
			['system', 'languages', static function () use ($ok, $fail) {
				$base = array_keys((array)require NU_ROOT . '/lang/en.php');
				$bad = [];
				foreach (['de', 'fr', 'it', 'es', 'pt', 'nl', 'pl', 'ru', 'zh', 'ar'] as $l) {
					$keys = array_keys((array)require NU_ROOT . '/lang/' . $l . '.php');
					$d = count(array_diff($base, $keys)) + count(array_diff($keys, $base));
					if ($d) $bad[] = $l . ' (' . $d . ')';
				}
				return $bad ? $fail(implode(', ', $bad)) : $ok(t('st_n_keys', count($base)));
			}],
			['system', 'security', static function () use ($ok, $warn) {
				$red = array_column(array_filter(NuSecurity::checks(), static fn($c) => $c['status'] === 'red'), 'title');
				return $red ? $warn(implode(' · ', $red)) : $ok();
			}],
			['system', 'mail', static function () use ($ok) {
				return NuMail::configured() ? $ok() : ['skip', t('ntf_f_email_off')];   // not set up is no fault (0.8.704): nothing to check
			}],
			// ------------------------------------------------------------ data
			['data', 'sample', static function () use ($ok, $fail) {
				$s = NuSample::status();
				return $s['installed'] ? $ok(t('st_sample_version', $s['version'], $s['current'])) : $fail(t('st_no_sample'));
			}],
			['data', 'browse', static function () use ($ok, $fail) {
				$meta = NuFormMeta::load('samplecustomer');
				if (!$meta) return $fail('samplecustomer');
				$r = NuBrowse::query($meta, NuHash::forForm($meta, ''));
				return $r['total'] > 0 ? $ok(t('st_n_rows', $r['total'])) : $fail(t('st_n_rows', 0));
			}],
			['data', 'list_views', static function () use ($ok, $fail) {
				$bad = [];
				foreach (NuListDefs::LISTS as $l) {
					try { [$from, $params] = NuListView::scope($l, false); nu_db()->value('SELECT COUNT(*)' . $from, $params); }
					catch (Throwable $e) {
						if (!preg_match('/doesn.t exist|Invalid object name|no such table|42S02/i', $e->getMessage())) $bad[] = $l . ': ' . $e->getMessage();   // a list of a missing nuBuilder table
					}
				}
				return $bad ? $fail(implode(' · ', $bad)) : $ok(t('st_n_lists', count(NuListDefs::LISTS)));
			}],
			['data', 'report', static function () use ($ok, $warn, $fail) {
				$r = NuReport::available() ? NuReport::load('sample_customers') : null;
				if (!$r) return $warn(t('st_missing', 'sample_customers'));
				$pdf = NuReport::pdf($r, []);
				return str_starts_with($pdf, '%PDF') ? $ok(t('st_bytes', strlen($pdf))) : $fail(substr($pdf, 0, 80));
			}],
			// ------------------------------------------------------------ forms (sample application)
			['forms', 'insert', static function () use ($ok, $fail) {
				$m = NuFormMeta::load('samplecustomer');
				if (!$m) return $fail(t('st_missing', 'samplecustomer'));
				$code = self::MARK . substr(bin2hex(random_bytes(3)), 0, 5);
				$r = NuRecord::save($m, ['record_id' => '-1', 'values' => ['cus_code' => $code, 'cus_name' => 'Self-test customer', 'cus_country' => 'CH'], 'edited' => []]);
				if (empty($r['ok'])) return $fail(json_encode($r['errors'] ?? $r, JSON_UNESCAPED_UNICODE));
				self::$made['customers'][] = (string)$r['record_id'];
				self::$made['code'] = $code;
				$row = nu_db()->one('SELECT cus_name FROM sample_customer WHERE sample_customer_id = ?', [$r['record_id']]);
				return $row && $row['cus_name'] === 'Self-test customer' ? $ok('#' . $r['record_id']) : $fail(t('record_not_found'));
			}],
			['forms', 'validate', static function () use ($ok, $fail) {
				$m = NuFormMeta::load('samplecustomer');
				$r1 = NuRecord::save($m, ['record_id' => '-1', 'values' => ['cus_code' => self::MARK . 'x' . random_int(100, 999), 'cus_name' => ''], 'edited' => []]);
				$r2 = NuRecord::save($m, ['record_id' => '-1', 'values' => ['cus_code' => (string)(self::$made['code'] ?? 'C001'), 'cus_name' => 'Duplicate'], 'edited' => []]);
				foreach ([$r1, $r2] as $r) if (!empty($r['ok'])) self::$made['customers'][] = (string)$r['record_id'];
				return empty($r1['ok']) && empty($r2['ok']) ? $ok(t('st_refused')) : $fail(t('st_saved_invalid'));
			}],
			['forms', 'update', static function () use ($ok, $fail) {
				$id = self::$made['customers'][0] ?? '';
				if ($id === '') return ['skip', ''];
				$m = NuFormMeta::load('samplecustomer');
				// as the browser does: all values of the record, the changed ones listed in "edited"
				$r = NuRecord::save($m, ['record_id' => $id, 'values' => ['cus_name' => 'Self-test customer (changed)', 'cus_phone' => '+41 00'] + (array)NuRecord::load($m, $id), 'edited' => ['cus_name', 'cus_phone']]);
				$row = nu_db()->one('SELECT cus_name, cus_phone FROM sample_customer WHERE sample_customer_id = ?', [$id]);
				return !empty($r['ok']) && $row && $row['cus_name'] === 'Self-test customer (changed)' ? $ok() : $fail(json_encode($r['errors'] ?? $row, JSON_UNESCAPED_UNICODE));
			}],
			['forms', 'subform', static function () use ($ok, $fail) {
				$cust = self::$made['customers'][0] ?? '';
				$prod = (string)nu_db()->value('SELECT sample_product_id FROM sample_product ORDER BY sample_product_id');
				if ($cust === '' || $prod === '') return ['skip', ''];
				$m = NuFormMeta::load('sampleorder');
				$row = static fn(string $pk, array $v, bool $del = false) => ['pk' => $pk, 'values' => $v, 'edited' => array_keys($v), 'deleted' => $del];
				$r = NuRecord::save($m, ['record_id' => '-1', 'values' => ['ord_number' => self::MARK . random_int(10000, 99999), 'ord_sample_customer_id' => $cust, 'ord_date' => date('Y-m-d'), 'ord_status' => 'open'],
					'edited' => [], 'subforms' => ['sample_items' => ['rows' => [$row('', ['ori_sample_product_id' => $prod, 'ori_qty' => '2', 'ori_price' => '10']),
						$row('', ['ori_sample_product_id' => $prod, 'ori_qty' => '1', 'ori_price' => '5'])]]]]);
				if (empty($r['ok'])) return $fail(json_encode($r['errors'] ?? $r, JSON_UNESCAPED_UNICODE));
				$oid = (string)$r['record_id'];
				self::$made['orders'][] = $oid;
				$items = nu_db()->all('SELECT sample_order_item_id AS id, ori_qty FROM sample_order_item WHERE ori_sample_order_id = ? ORDER BY ori_price DESC', [$oid]);
				if (count($items) !== 2) return $fail(t('st_n_rows', count($items)));
				// change the first row, delete the second
				$r2 = NuRecord::save($m, ['record_id' => $oid, 'values' => (array)NuRecord::load($m, $oid), 'edited' => [], 'subforms' => ['sample_items' => ['rows' => [
					$row((string)$items[0]['id'], ['ori_qty' => '7']), $row((string)$items[1]['id'], [], true)]]]]);
				$after = nu_db()->all('SELECT ori_qty FROM sample_order_item WHERE ori_sample_order_id = ?', [$oid]);
				return !empty($r2['ok']) && count($after) === 1 && (float)$after[0]['ori_qty'] === 7.0 ? $ok(t('st_subform_ok')) : $fail(json_encode(empty($r2['ok']) ? ($r2['errors'] ?? $r2) : $after, JSON_UNESCAPED_UNICODE));
			}],
			['forms', 'hash', static function () use ($ok, $fail) {
				$id = self::$made['customers'][0] ?? '';
				$m = NuFormMeta::load('samplecustomer');
				$h = NuHash::forForm($m, $id, (array)NuRecord::load($m, $id));
				$miss = array_values(array_filter(['RECORD_ID', 'FORM_ID', 'USER_ID', 'TABLE_ID', 'cus_name'], static fn($k) => !array_key_exists($k, $h)));
				return $miss ? $fail(t('st_missing', implode(', ', $miss))) : $ok(t('st_n_keys', count($h)));
			}],
			['forms', 'audit', static function () use ($ok, $warn, $fail) {
				$id = self::$made['customers'][0] ?? '';
				if (!NuAudit::on()) return $warn(t('aud_off'));
				if ($id === '') return ['skip', ''];
				$hist = NuAudit::history('sample_customer', $id);
				$rows = array_merge(...array_map(static fn($c) => $c['rows'], $hist ?: [['rows' => []]]));
				$upd = array_values(array_filter($rows, static fn($r) => $r['action'] === 'update' && $r['column_name'] === 'cus_name'));
				if (!$upd) return $fail(t('st_audit_missing'));
				NuAudit::restore((string)$upd[0]['zzzzsys_nunext_audit_id'], 'samplecustomer');
				$name = (string)nu_db()->value('SELECT cus_name FROM sample_customer WHERE sample_customer_id = ?', [$id]);
				return $name === 'Self-test customer' ? $ok(t('st_n_rows', count($rows))) : $fail(t('st_restore_failed', $name));
			}],
			['forms', 'delete', static function () use ($ok, $fail) {
				$bad = [];
				foreach (self::$made['orders'] as $oid) {
					$r = NuRecord::delete(NuFormMeta::load('sampleorder'), $oid);
					if (empty($r['ok']) || nu_db()->value('SELECT COUNT(*) FROM sample_order_item WHERE ori_sample_order_id = ?', [$oid])) $bad[] = $oid;
				}
				foreach (self::$made['customers'] as $cid) {
					$r = NuRecord::delete(NuFormMeta::load('samplecustomer'), $cid);
					if (empty($r['ok']) || nu_db()->value('SELECT COUNT(*) FROM sample_customer WHERE sample_customer_id = ?', [$cid])) $bad[] = $cid;
				}
				return $bad ? $fail(implode(', ', $bad)) : $ok(t('st_n_rows', count(self::$made['orders']) + count(self::$made['customers'])));
			}],
			// ------------------------------------------------------------ rights
			['rights', 'levels', static function () use ($db, $ok, $fail) {
				$bad = [];
				$n = 0;
				foreach ($db->all("SELECT slf_zzzzsys_access_id AS a, slf_zzzzsys_form_id AS f, slf_save_button AS s, slf_delete_button AS d FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id LIKE 'sample%'") as $r) {
					$x = NuAcl::levelRights((string)$r['a'], (string)$r['f']);
					$n++;
					if (!$x['view'] || $x['save'] !== ((string)$r['s'] === '1') || $x['delete'] !== ((string)$r['d'] === '1')) $bad[] = $r['a'] . '/' . $r['f'];
				}
				$none = NuAcl::levelRights('__none__', 'samplecustomer');
				if ($none['view']) $bad[] = 'no row → view';
				return $bad ? $fail(implode(', ', $bad)) : $ok(t('st_n_rows', $n));
			}],
			['rights', 'admin_roles', static function () use ($ok, $fail) {
				$bad = [];
				foreach (NuAdmin::RIGHTS as $k => $r) if (!isset(NuAdmin::GROUPS[$r[0]]) || t($r[1]) === $r[1]) $bad[] = $k;
				return $bad ? $fail(implode(', ', $bad)) : $ok(t('st_n_rows', count(NuAdmin::RIGHTS)));
			}],
			// ------------------------------------------------------------ features
			['features', 'notifications', static function () use ($ok, $fail) {
				[$id, $e] = NuNotify::save('', ['title' => 'Self-test', 'body' => 'x', 'level' => 'info', 'audience' => ['users' => ['globeadmin']]], true, null, false);
				if ($e) return $fail(json_encode($e, JSON_UNESCAPED_UNICODE));
				self::$made['notifications'][] = $id;
				$before = NuNotify::summary()['unread'];
				$got = in_array($id, NuNotify::summary()['unread_ids'], true);
				NuNotify::setState($id, 'read');
				$after = NuNotify::summary()['unread'];
				return $got && $after === $before - 1 ? $ok() : $fail('unread ' . $before . ' → ' . $after);
			}],
			['features', 'home_hook', static function () use ($ok, $fail) {        // start page hooks (0.8.378): the PHP functions and the merge, without a procedure
				$code = "nuHomeAddGroup('x_st', 'Self-test'); nuHomeAddTile('x_st_tile', ['group' => 'x_st', 'label' => 'Self-test', 'page' => 'help']); nuHomeSetBadge('x_st_tile', '1', 'info'); nuHomeHideTile('x_hidden');";
				NuHomeHook::reset();
				$r = NuPhp::isolated(static fn() => NuPhp::run($code, NuHash::plain(), 'selftest'));
				if ($r['errors'] || NuHomeHook::$problems) return $fail(implode(' · ', array_merge(array_column($r['errors'], 'message'), NuHomeHook::$problems)));
				$prev = NuHomeHook::$ran;
				NuHomeHook::$ran = true;
				$g = NuHomeHook::apply([['id' => 'g', 'title' => '', 'tab' => '', 'items' => ['x_hidden' => ['label' => 'x']]]], ['tabs' => []]);
				NuHomeHook::$ran = $prev;
				NuHomeHook::reset();
				$tile = $g[0]['items']['x_st_tile'] ?? null;
				return count($g) === 1 && $g[0]['id'] === 'x_st' && $tile && ($tile['badge'][0] ?? '') === '1' ? $ok(NuHomeHook::active() ? t('st_hook_active') : '') : $fail(json_encode($g));
			}],
			['features', 'housekeeping', static function () use ($ok, $warn) {
				$h = NuHousekeeping::last();
				if (!$h) return $warn(t('st_never'));
				$age = time() - $h['time'];
				return $age > 2 * 86400 ? $warn(t('st_hk_old', date(nu_dfmt('d.m.Y H:i'), $h['time']), $h['source'])) : $ok(date(nu_dfmt('d.m.Y H:i'), $h['time']) . ' · ' . $h['source']);
			}],
		];
	}

	/** Removes what a run left behind (also after an error in the middle). */
	private static function cleanup(): array
	{
		$t = hrtime(true);
		$db = nu_db();
		$n = 0;
		try {
			foreach (self::$made['orders'] ?? [] as $oid) {
				$n += $db->run('DELETE FROM sample_order_item WHERE ori_sample_order_id = ?', [$oid])->rowCount();
				$n += $db->run('DELETE FROM sample_order WHERE sample_order_id = ?', [$oid])->rowCount();
			}
			foreach (self::$made['customers'] ?? [] as $cid) $n += $db->run('DELETE FROM sample_customer WHERE sample_customer_id = ?', [$cid])->rowCount();
			if ($db->tableExists('sample_customer')) $n += $db->run('DELETE FROM sample_customer WHERE cus_code LIKE ?', [self::MARK . '%'])->rowCount();
			$ids = array_merge(self::$made['orders'] ?? [], self::$made['customers'] ?? []);
			if ($ids && $db->tableExists(NuAudit::TABLE)) {
				$in = implode(',', array_fill(0, count($ids), '?'));
				$db->run('DELETE FROM ' . NuAudit::TABLE . " WHERE record_id IN ($in) OR parent_id IN ($in)", [...$ids, ...$ids]);
			}
			foreach (self::$made['notifications'] ?? [] as $id) NuNotify::delete($id);
			return ['group' => 'system', 'id' => 'cleanup', 'title' => t('st_cleanup'), 'status' => 'ok', 'detail' => t('st_n_rows', $n), 'ms' => (int)((hrtime(true) - $t) / 1e6)];
		} catch (Throwable $e) {
			return ['group' => 'system', 'id' => 'cleanup', 'title' => t('st_cleanup'), 'status' => 'fail', 'detail' => $e->getMessage(), 'ms' => 0];
		}
	}
}
