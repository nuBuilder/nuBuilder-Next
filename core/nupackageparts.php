<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * The parts of a project package (NuProjectPackage) that are not rows of one table: access levels and settings.
 *  - Access levels travel by their code (ids differ between installations): the row of zzzzsys_access (shared with nuBuilder Forte)
 *    and the start page mode (tile page or Home form). A new level keeps its id when the id is free here; a replaced one keeps its own
 *    id and only takes the values. Their rights come with the forms, procedures and reports (NuProjectPackage RIGHTS).
 *  - Settings travel only in the package of the whole application, one part each (PARTS). What belongs to one installation never
 *    travels: the secret key and nuconfig.php, SMTP, single sign-on, IP access, the health key, API clients, webhook secrets, the
 *    environment label, update and GitHub settings. Values whose name looks like a secret are left out of the shared settings too.
 */
final class NuPackageParts
{
	/** Columns of an access level compared and written (not the id, not the code – the code finds it). */
	public const LEVEL_COLS = ['sal_description', 'sal_group', 'sal_zzzzsys_form_id', 'sal_use_2fa', 'sal_json'];
	/** Setting parts of the application package, in the order of the import page. */
	public const PARTS = ['shared', 'globalcode', 'branding', 'themes', 'languages', 'security', 'pwpolicy', 'audit', 'pages'];
	/** Parts that are rows of zzzzsys_nunext_setting: part => setting ids. */
	private const ROWS = ['languages' => ['languages', 'language_variants'], 'security' => ['security'], 'pwpolicy' => ['pw_policy'], 'audit' => ['audit']];
	/** Branding keys of this installation only. */
	private const BRAND_LOCAL = ['env_label', 'env_color', 'rev'];
	/** Columns of a custom page's settings (zzzzsys_nunext_page, by form code). */
	private const PAGE_COLS = ['open_mode', 'iframe_height', 'settings', 'csp', 'note'];

	// ------------------------------------------------------------------ access levels

	/** The package entry of a level (by id): its code, row and start page mode; null when it does not exist. */
	public static function level(string $id): ?array
	{
		$r = NuAcl::level($id);
		if (!$r) return null;
		$row = ['zzzzsys_access_id' => (string)$r['zzzzsys_access_id'], 'sal_code' => (string)$r['sal_code']];
		foreach (self::LEVEL_COLS as $c) if (array_key_exists($c, $r)) $row[$c] = $r[$c] === null ? null : (string)$r[$c];
		return ['code' => (string)$r['sal_code'], 'row' => $row, 'start' => NuHome::startMode($id) === 'form' ? 'form' : 'tiles'];
	}

	/** Id of the level with this code here ('' = none). */
	public static function levelId(string $code): string
	{
		$id = array_search($code, NuAcl::levelCodes(), true);
		return $id === false ? '' : (string)$id;
	}

	/** Names of the values of a level that differ here; [] = the same. */
	public static function levelDiff(array $entry): array
	{
		$here = self::level(self::levelId((string)($entry['code'] ?? '')));
		if (!$here) return ['new'];
		$out = [];
		foreach (self::LEVEL_COLS as $c) {
			if (!array_key_exists($c, (array)($entry['row'] ?? []))) continue;
			if ((string)($here['row'][$c] ?? '') !== (string)($entry['row'][$c] ?? '')) $out[] = $c;
		}
		if (($here['start'] ?? '') !== ($entry['start'] ?? 'tiles')) $out[] = 'start';
		return $out;
	}

	/** Adds or updates a level by its code. @return string the level's id here */
	public static function writeLevel(NuDB $db, array $entry): string
	{
		$code = trim((string)($entry['code'] ?? ''));
		if ($code === '' || mb_strlen($code) > 50) throw new RuntimeException(t('err_invalid'));
		$vals = [];
		foreach (self::LEVEL_COLS as $c) if (array_key_exists($c, (array)($entry['row'] ?? []))) $vals[$c] = $entry['row'][$c];
		$id = self::levelId($code);
		if ($id !== '') $db->update('zzzzsys_access', $vals, 'zzzzsys_access_id', $id);
		else {
			$want = (string)($entry['row']['zzzzsys_access_id'] ?? '');
			$id = $want !== '' && strlen($want) <= 25 && !NuAcl::levelExists($want) ? $want : nu_id();
			$db->insert('zzzzsys_access', ['zzzzsys_access_id' => $id, 'sal_code' => $code] + $vals);
		}
		NuMemo::drop('acl:levels');
		NuHome::setStartMode($id, ($entry['start'] ?? '') === 'form' ? 'form' : 'tiles');
		return $id;
	}

	// ------------------------------------------------------------------ settings

	/** Every setting part of this installation: list of {part, value}. */
	public static function settings(?array $parts = null): array
	{
		$out = [];
		foreach ($parts ?? self::PARTS as $p) {
			try {
				$v = self::capture($p);
				if ($v !== null) $out[] = ['part' => $p, 'value' => $v];
			} catch (Throwable $ig) { nu_ignored($ig, 'setting part ' . $p); }
		}
		return $out;
	}

	/** One part as it is here (null when this installation has nothing of it). */
	public static function capture(string $part): ?array
	{
		$db = nu_db();
		switch ($part) {
			case 'shared':
				if (!$db->tableExists('zzzzsys_config')) return null;
				$v = [];
				foreach ($db->all('SELECT cfg_setting, cfg_value FROM zzzzsys_config') as $r) {
					$k = (string)$r['cfg_setting'];
					if ($k === '' || self::localShared($k)) continue;
					$v[$k] = (string)($r['cfg_value'] ?? '');
				}
				ksort($v);
				return $v;
			case 'globalcode':
				$s = $db->tableExists('zzzzsys_setup') ? $db->one('SELECT set_header, set_style, set_include FROM zzzzsys_setup ORDER BY zzzzsys_setup_id') : null;
				$c = NuCustomCode::get();
				return ['header' => (string)($s['set_header'] ?? ''), 'style' => (string)($s['set_style'] ?? ''), 'include' => (string)($s['set_include'] ?? ''),
					'js' => (string)$c['js'], 'css' => (string)$c['css'], 'scope' => (array)$c['scope']];
			case 'branding':
				$b = nu_setting_json(NuBranding::SETTING);
				foreach (self::BRAND_LOCAL as $k) unset($b[$k]);
				ksort($b);
				$files = [];
				if ($db->tableExists('zzzzsys_file')) {
					foreach ($db->all('SELECT zzzzsys_file_id, sfi_description, sfi_group, sfi_json FROM zzzzsys_file WHERE zzzzsys_file_id IN (?, ?)
						ORDER BY zzzzsys_file_id', [NuBranding::FILE_LIGHT, NuBranding::FILE_DARK]) as $r) {
						$files[(string)$r['zzzzsys_file_id']] = ['d' => (string)$r['sfi_description'], 'g' => (string)$r['sfi_group'], 'j' => (string)$r['sfi_json']];
					}
				}
				return ['settings' => $b, 'logos' => $files];
			case 'themes':
				if (!$db->tableExists(NuTheme::TABLE)) return null;
				$rows = [];
				foreach ($db->all('SELECT * FROM ' . NuTheme::TABLE . ' ORDER BY ' . NuTheme::TABLE . '_id') as $r) {
					foreach (['created_at', 'created_by', 'updated_at', 'updated_by'] as $k) unset($r[$k]);
					$rows[] = $r;
				}
				$s = nu_setting_json(NuTheme::SETTING);
				// the themes of access levels by level code (ids differ between installations)
				$codes = NuAcl::levelCodes();
				$lv = [];
				foreach ((array)($s['levels'] ?? []) as $id => $t) if (isset($codes[(string)$id])) $lv[$codes[(string)$id]] = $t;
				ksort($lv);
				$s['levels'] = $lv;
				unset($s['rev']);
				ksort($s);
				return ['themes' => $rows, 'settings' => $s];
			case 'pages':
				if (!$db->tableExists('zzzzsys_nunext_page')) return null;
				$v = [];
				foreach ($db->all('SELECT * FROM zzzzsys_nunext_page') as $r) {
					$code = (string)(nu_form((string)$r['zzzzsys_nunext_page_id'])['code'] ?? '');
					if ($code === '') continue;
					foreach (self::PAGE_COLS as $c) $v[$code][$c] = $r[$c] ?? null;
				}
				ksort($v);
				return $v;
			default:
				if (!isset(self::ROWS[$part])) return null;
				$v = [];
				foreach (self::ROWS[$part] as $id) $v[$id] = nu_setting_value($id);
				return $v;
		}
	}

	/** Shared settings of one installation: demo mode, who is globeadmin, database tools, backups, debugging – a test system keeps its own. */
	private const SHARED_LOCAL = ['$nuConfigIsDemo', '$nuConfigDemoSavingAllowedIds', '$nuConfigDBGlobeadminUsers', '$nuConfigEnableDatabaseUpdate',
		'$nuConfigBackupLocation', '$nuConfigPrimaryDatabaseManagement', 'nuDebugMode', 'nuShowJSErrors'];

	/** Shared settings that belong to this installation: secrets, the demo log-in and SHARED_LOCAL. */
	private static function localShared(string $name): bool
	{
		return NuSecurity::secretName($name) || str_starts_with($name, '$nuConfigDemoDB') || in_array($name, self::SHARED_LOCAL, true);
	}

	/** Names of the values that differ between the package and here. @return list<string> */
	public static function diff(string $part, mixed $theirs): array
	{
		$mine = self::capture($part) ?? [];
		$theirs = is_array($theirs) ? $theirs : [];
		$out = [];
		if ($part === 'shared') {
			foreach ($theirs as $k => $v) if (array_key_exists($k, $mine) && (string)$mine[$k] !== (string)$v) $out[] = (string)$k;
			return $out;
		}
		if ($part === 'pages') {
			foreach ($theirs as $code => $v) if (self::formByCode((string)$code) !== '') {
				if (self::canon($mine[$code] ?? null) !== self::canon($v)) $out[] = (string)$code;
			}
			return $out;
		}
		if ($part === 'themes') {
			$by = static fn(array $rows): array => array_column(array_map(static fn($r) => ['k' => (string)reset($r), 'v' => self::canon($r)], $rows), 'v', 'k');
			$a = $by((array)($mine['themes'] ?? [])); $b = $by((array)($theirs['themes'] ?? []));
			foreach ($b as $k => $v) if (($a[$k] ?? null) !== $v) $out[] = $k;
			if (self::canon($mine['settings'] ?? []) !== self::canon($theirs['settings'] ?? [])) $out[] = 'theme';
			return $out;
		}
		foreach (array_unique(array_merge(array_keys($mine), array_keys($theirs))) as $k) {
			if (self::canon($mine[$k] ?? null) !== self::canon($theirs[$k] ?? null)) $out[] = (string)$k;
		}
		return $out;
	}

	/** Writes one part. */
	public static function apply(string $part, mixed $v): void
	{
		if (!in_array($part, self::PARTS, true) || !is_array($v)) return;
		$db = nu_db();
		$by = mb_substr((string)(NuAuth::user()['login'] ?? ''), 0, 50);
		$now = date('Y-m-d H:i:s');
		switch ($part) {
			case 'shared':
				foreach ($v as $k => $val) {
					if (!is_string($k) || self::localShared($k)) continue;
					$db->run('UPDATE zzzzsys_config SET cfg_value = ? WHERE cfg_setting = ?', [(string)$val, $k]);   // only settings this installation has
				}
				break;
			case 'globalcode':
				$id = $db->value('SELECT zzzzsys_setup_id FROM zzzzsys_setup ORDER BY zzzzsys_setup_id');
				if ($id !== null && $id !== false) $db->update('zzzzsys_setup', ['set_header' => (string)($v['header'] ?? ''), 'set_style' => (string)($v['style'] ?? ''),
					'set_include' => (string)($v['include'] ?? '')], 'zzzzsys_setup_id', (string)$id);
				NuCustomCode::save((string)($v['js'] ?? ''), (string)($v['css'] ?? ''), (array)($v['scope'] ?? []));
				break;
			case 'branding':
				$cur = nu_setting_json(NuBranding::SETTING);
				$new = (array)($v['settings'] ?? []);
				foreach (self::BRAND_LOCAL as $k) { unset($new[$k]); if (array_key_exists($k, $cur)) $new[$k] = $cur[$k]; }
				$new['rev'] = substr(bin2hex(random_bytes(4)), 0, 8);      // new style sheet address: browsers fetch it again
				nu_setting_json_save(NuBranding::SETTING, $new, $by);
				NuFile::ensureTable();
				foreach ([NuBranding::FILE_LIGHT, NuBranding::FILE_DARK] as $fid) {
					$db->run('DELETE FROM zzzzsys_file WHERE zzzzsys_file_id = ? OR sfi_code = ?', [$fid, $fid]);
					$f = $v['logos'][$fid] ?? null;
					if (is_array($f) && (string)($f['j'] ?? '') !== '') {
						$db->insert(NuFile::TABLE, ['zzzzsys_file_id' => $fid, 'sfi_code' => $fid, 'sfi_description' => (string)($f['d'] ?? $fid),
							'sfi_group' => (string)($f['g'] ?? 'nunext'), 'sfi_json' => (string)$f['j'], 'sfi_system' => '0']);
					}
				}
				break;
			case 'themes':
				NuSchema::ensure();
				$t = NuTheme::TABLE;
				foreach ((array)($v['themes'] ?? []) as $r) {
					$r = (array)$r;
					$id = (string)($r[$t . '_id'] ?? '');
					if ($id === '') continue;
					$cols = $db->columns($t);
					$row = $cols ? array_intersect_key($r, $cols) : $r;
					$db->delete($t, $t . '_id', $id);
					$db->insert($t, $row + (isset($cols['updated_at']) ? ['updated_at' => $now] : []) + (isset($cols['updated_by']) ? ['updated_by' => $by] : []));
				}
				$s = (array)($v['settings'] ?? []);
				$lv = [];
				foreach ((array)($s['levels'] ?? []) as $code => $th) { $id = self::levelId((string)$code); if ($id !== '') $lv[$id] = $th; }
				$s['levels'] = (object)$lv;
				$s['rev'] = substr(bin2hex(random_bytes(4)), 0, 8);
				nu_setting_json_save(NuTheme::SETTING, $s, $by);
				break;
			case 'pages':
				foreach ($v as $code => $vals) {
					$fid = self::formByCode((string)$code);
					if ($fid === '' || !is_array($vals)) continue;
					$row = array_intersect_key($vals, array_flip(self::PAGE_COLS));
					if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_page WHERE zzzzsys_nunext_page_id = ?', [$fid])) $db->update('zzzzsys_nunext_page', $row, 'zzzzsys_nunext_page_id', $fid);
					else $db->insert('zzzzsys_nunext_page', ['zzzzsys_nunext_page_id' => $fid] + $row);
				}
				break;
			default:
				foreach (self::ROWS[$part] as $id) {
					if (!array_key_exists($id, $v)) continue;
					if ($v[$id] === null) $db->run('DELETE FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id]);
					else nu_setting_save($id, ['setting_value' => (string)$v[$id], 'updated_at' => $now, 'updated_by' => $by], $db);
				}
		}
		nu_setting_forget();
	}

	/** Id of the form with this code here ('' = none). */
	private static function formByCode(string $code): string
	{
		foreach (nu_forms() as $id => $f) if ((string)($f['code'] ?? '') === $code) return (string)$id;
		return '';
	}

	private static function canon(mixed $v): string
	{
		if (is_array($v)) { ksort($v); return (string)json_encode(array_map(static fn($x) => is_array($x) ? self::canon($x) : ($x === null ? null : (string)$x), $v)); }
		return $v === null ? '' : (string)$v;
	}
}
