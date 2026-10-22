<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Reports – nuBuilder's zzzzsys_report, run as PDF like nuBuilder's core/nurunpdf.php (TCPDF).
 *
 *   sre_code, sre_description, sre_group     list
 *   sre_zzzzsys_form_id                      launch form (criteria the user fills before printing), optional
 *   sre_zzzzsys_php_id                       data source: PROCEDURE:<zzzzsys_php_id> (fills #TABLE_ID#), TABLE:<name>, SQL:<zzzzsys_select_id>
 *   sre_layout                               JSON: paper, orientation, ishtml, groups → sections → objects (positions in px, 4 px = 1 mm)
 *   zzzzsys_access_report                    which access levels may run it (sre_zzzzsys_access_id, sre_zzzzsys_report_id)
 *
 * Rights to edit: nuBuilder's system form "nubuildreport". Rights to run: the globeadmin, or an access_report row of the user's level.
 * TCPDF is not bundled (about 27 MB with fonts): nuBuilder's third_party/tcpdf is used (see tcpdfDir()).
 */
final class NuReport
{
	public const TABLE  = 'zzzzsys_report';
	public const ACCESS = 'zzzzsys_access_report';
	/** Paper sizes of nuBuilder's designer: [height, width] in mm, portrait. */
	public const PAPER = ['A4' => [297, 210], 'A5' => [210, 148], 'Letter' => [279.4, 215.9], 'Legal' => [355.6, 215.9]];
	/** Group indexes of the layout: 0 detail, 1 report, 2 page, 3–7 the sort groups. */
	public const SORT_GROUPS = [3, 4, 5, 6, 7];

	// ================================================================= tables

	/** Creates nuBuilder's report and SQL builder tables when they are missing (a database without nuBuilder). */
	public static function ensureTables(): void
	{
		$db = nu_db();
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$opt = $db->type === 'mysql' ? NuDDL::mysqlTableOptions() : '';
		$tables = [
			'zzzzsys_report' => 'zzzzsys_report_id ' . $T('id') . ' NOT NULL PRIMARY KEY, sre_code ' . $T('text', '300') . ' NULL, sre_description ' . $T('text', '300') . ' NULL, '
				. 'sre_group ' . $T('text', '100') . ' NULL, sre_zzzzsys_php_id ' . $T('text', '200') . ' NULL, sre_zzzzsys_form_id ' . $T('id') . ' NULL, '
				. 'sre_layout ' . $T('longtext') . ' NULL, sre_json ' . $T('longtext') . ' NULL',
			'zzzzsys_access_report' => 'zzzzsys_access_report_id ' . $T('id') . ' NOT NULL PRIMARY KEY, sre_zzzzsys_access_id ' . $T('id') . ' NULL, '
				. 'sre_zzzzsys_report_id ' . $T('id') . ' NULL, srp_json ' . $T('longtext') . ' NULL',
			'zzzzsys_select' => 'zzzzsys_select_id ' . $T('id') . ' NOT NULL PRIMARY KEY, sse_code ' . $T('text', '50') . ' NULL, sse_description ' . $T('text', '300') . ' NULL, '
				. 'sse_json ' . $T('longtext') . ' NULL, sse_sql ' . $T('longtext') . ' NULL, sse_edit ' . $T('text', '1') . ' NULL, sse_system ' . $T('text', '1') . ' NULL',
			'zzzzsys_select_clause' => 'zzzzsys_select_clause_id ' . $T('id') . ' NOT NULL PRIMARY KEY, ssc_zzzzsys_select_id ' . $T('id') . ' NULL, '
				. 'ssc_type ' . $T('text', '300') . ' NULL, ssc_field ' . $T('text', '500') . ' NULL, ssc_clause ' . $T('text', '500') . ' NULL, '
				. 'ssc_sort ' . $T('text', '10') . ' NULL, ssc_order ' . $T('text', '500') . ' NULL, ssc_json ' . $T('longtext') . ' NULL',
		];
		foreach ($tables as $name => $cols) {
			if ($db->tableExists($name)) continue;
			$db->run("CREATE TABLE $name ($cols)$opt");
			NuDB::forgetColumns($name);
		}
	}

	public static function available(): bool
	{
		static $ok = null;
		try {
			return $ok ??= nu_db()->tableExists(self::TABLE);
		} catch (Throwable) {
			return false;
		}
	}

	// ================================================================= records

	/** Report by id or code (nuBuilder's run functions take the code, run objects the id). */
	public static function load(string $idOrCode): ?array
	{
		if ($idOrCode === '' || !self::available()) return null;
		$db = nu_db();
		return $db->one('SELECT * FROM zzzzsys_report WHERE zzzzsys_report_id = ?', [$idOrCode])
			?? $db->one('SELECT * FROM zzzzsys_report WHERE sre_code = ?', [$idOrCode]);
	}

	/** May the current user run the report? The globeadmin always; others with a zzzzsys_access_report row of their access level. */
	public static function canRun(array $r): bool
	{
		$u = NuAuth::user();
		if (!$u) return false;
		if ($u['globeadmin']) return true;
		if (!nu_db()->tableExists(self::ACCESS)) return false;
		return (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_access_report WHERE sre_zzzzsys_access_id = ? AND sre_zzzzsys_report_id = ?',
			[(string)$u['access_id'], (string)$r['zzzzsys_report_id']]);
	}

	/** Access levels with [zzzzsys_access_id, sal_code, sal_description, sal_group, granted]. */
	public static function access(string $id): array
	{
		$db = nu_db();
		$granted = $id !== '' && $db->tableExists(self::ACCESS)
			? array_flip(array_column($db->all('SELECT sre_zzzzsys_access_id FROM zzzzsys_access_report WHERE sre_zzzzsys_report_id = ?', [$id]), 'sre_zzzzsys_access_id')) : [];
		$out = [];
		foreach ($db->all('SELECT zzzzsys_access_id, sal_code, sal_description, sal_group FROM zzzzsys_access ORDER BY sal_group, sal_code') as $a) {
			$out[] = $a + ['granted' => isset($granted[$a['zzzzsys_access_id']])];
		}
		return $out;
	}

	/** Sets the access levels that may run the report. @return array{added: string[], removed: string[]} access level codes */
	public static function setAccess(string $id, array $accessIds): array
	{
		$db = nu_db();
		$codes = array_column($db->all('SELECT zzzzsys_access_id, sal_code FROM zzzzsys_access'), 'sal_code', 'zzzzsys_access_id');
		$want = array_values(array_intersect(array_map('strval', $accessIds), array_keys($codes)));
		$have = array_column($db->all('SELECT sre_zzzzsys_access_id FROM zzzzsys_access_report WHERE sre_zzzzsys_report_id = ?', [$id]), 'sre_zzzzsys_access_id');
		$add = array_diff($want, $have);
		$del = array_diff($have, $want);
		foreach ($add as $a) $db->insert(self::ACCESS, ['zzzzsys_access_report_id' => nu_id(), 'sre_zzzzsys_access_id' => $a, 'sre_zzzzsys_report_id' => $id]);
		foreach ($del as $a) $db->run('DELETE FROM zzzzsys_access_report WHERE sre_zzzzsys_report_id = ? AND sre_zzzzsys_access_id = ?', [$id, $a]);
		return ['added' => array_map(static fn($a) => (string)($codes[$a] ?? $a), array_values($add)), 'removed' => array_map(static fn($a) => (string)($codes[$a] ?? $a), array_values($del))];
	}

	/** Fields compared for the change history. */
	public const FIELDS = ['sre_code', 'sre_description', 'sre_group', 'sre_zzzzsys_form_id', 'sre_zzzzsys_php_id', 'sre_layout'];

	/** Validates and saves code, description, group, launch form, data source (not the layout unless given). @return array{0: string, 1: array} */
	public static function save(array $in, string $id = ''): array
	{
		$db = nu_db();
		$row = [];
		foreach (['sre_code' => 300, 'sre_description' => 300, 'sre_group' => 100] as $k => $len) $row[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, $len);
		$row['sre_zzzzsys_form_id'] = trim((string)($in['sre_zzzzsys_form_id'] ?? '')) ?: null;
		$row['sre_zzzzsys_php_id']  = trim((string)($in['sre_zzzzsys_php_id'] ?? ''));
		$errors = [];
		if ($row['sre_code'] === '') $errors['sre_code'] = t('err_required');
		elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_report WHERE sre_code = ? AND zzzzsys_report_id <> ?', [$row['sre_code'], $id])) $errors['sre_code'] = t('rpt_err_code_taken');
		if ($row['sre_description'] === '') $errors['sre_description'] = t('err_required');
		if ($row['sre_zzzzsys_php_id'] === '') $errors['sre_zzzzsys_php_id'] = t('err_required');
		elseif (!preg_match('/^(PROCEDURE|TABLE|SQL):\S+$/', $row['sre_zzzzsys_php_id'])) $errors['sre_zzzzsys_php_id'] = t('rpt_err_source');
		if ($row['sre_zzzzsys_form_id'] !== null && !$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$row['sre_zzzzsys_form_id']])) $errors['sre_zzzzsys_form_id'] = t('form_err_not_found');
		if (array_key_exists('sre_layout', $in)) {
			$row['sre_layout'] = self::layoutJson($in['sre_layout']);
			if ($id !== '') $row['sre_layout'] = self::keepTypes($row['sre_layout'], (string)$db->value('SELECT sre_layout FROM zzzzsys_report WHERE zzzzsys_report_id = ?', [$id]));
		}
		if ($errors) return [$id, $errors];
		if ($id === '') {
			$id = nu_id();
			if (!isset($row['sre_layout'])) $row['sre_layout'] = json_encode(self::defaultLayout(), JSON_UNESCAPED_UNICODE);
			$db->insert(self::TABLE, ['zzzzsys_report_id' => $id] + $row);
		} else {
			$db->update(self::TABLE, $row, 'zzzzsys_report_id', $id);
		}
		return [$id, []];
	}

	/** Changed fields [field => [old, new]] for the history (the layout as "changed" only). */
	public static function diff(?array $old, array $new): array
	{
		$out = [];
		foreach (self::FIELDS as $f) {
			$o = $old === null ? null : (string)($old[$f] ?? '');
			$n = (string)($new[$f] ?? '');
			if ($o === $n) continue;
			$out[$f] = $f === 'sre_layout' ? [$o === null ? null : '…', '…'] : [$o, $n];
		}
		return $out;
	}

	public static function history(string $id, int $limit = 100): array
	{
		try {
			NuSchema::ensure();
			return nu_db()->page("SELECT log_time, login, event, details FROM zzzzsys_nunext_log WHERE object = 'zzzzsys_report' AND record_id = ?", [$id], 'log_time DESC, zzzzsys_nunext_log_id DESC', $limit, 0);
		} catch (Throwable) {
			return [];
		}
	}

	/** Where the report is used: run objects, and code that names its code (nuRunReport('code') …). */
	public static function usage(string $id, string $code): array
	{
		$db = nu_db();
		$out = [];
		foreach ($db->all("SELECT o.sob_all_id, o.sob_all_label, f.sfo_code, f.zzzzsys_form_id FROM zzzzsys_object o LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
			WHERE o.sob_all_type = 'run' AND o.sob_run_zzzzsys_form_id = ?", [$id]) as $r) {
			$out[] = ['kind' => 'object', 'form' => (string)$r['sfo_code'], 'form_id' => (string)$r['zzzzsys_form_id'], 'where' => trim($r['sob_all_id'] . ' · ' . strip_tags((string)$r['sob_all_label']), ' ·')];
		}
		if ($code !== '') {
			$like = NuDB::like($code);
			foreach ($db->all("SELECT zzzzsys_form_id, sfo_code FROM zzzzsys_form WHERE sfo_javascript LIKE ? ESCAPE '!' OR sfo_browse_javascript LIKE ? ESCAPE '!' OR sfo_edit_javascript LIKE ? ESCAPE '!'", [$like, $like, $like]) as $f) {
				$out[] = ['kind' => 'code', 'form' => (string)$f['sfo_code'], 'form_id' => (string)$f['zzzzsys_form_id'], 'where' => 'JavaScript'];
			}
			foreach ($db->all("SELECT zzzzsys_php_id, sph_code FROM zzzzsys_php WHERE sph_php LIKE ? ESCAPE '!' OR sph_javascript LIKE ? ESCAPE '!'", [$like, $like]) as $p) {
				$out[] = ['kind' => 'code', 'form' => '', 'form_id' => '', 'where' => (string)$p['sph_code'], 'proc_id' => NuProcedure::isHook((string)$p['zzzzsys_php_id']) ? '' : (string)$p['zzzzsys_php_id']];
			}
		}
		return $out;
	}

	// ================================================================= layout

	/** nuBuilder's empty layout (nureportjson.js nuREPORTdefault): A4 portrait, detail, report, page and five sort groups. */
	public static function defaultLayout(): array
	{
		$sec = static fn(string $label, int $height) => ['id' => '', 'top' => 0, 'height' => $height, 'label' => $label, 'page_break' => 0, 'margins' => 0, 'color' => '#FFFFFF', 'objects' => []];
		$groups = [
			['sortField' => 'Detail', 'sortBy' => 'a', 'sections' => [$sec('Detail Header', 40)]],
			['sortField' => 'Report', 'sortBy' => 'a', 'sections' => [$sec('Report Header', 40), $sec('Report Footer', 40)]],
			['sortField' => 'Page', 'sortBy' => 'a', 'sections' => [$sec('Page Header', 40), $sec('Page Footer', 40)]],
		];
		for ($i = 0; $i < 5; $i++) $groups[] = ['sortField' => '', 'sortBy' => 'a', 'sections' => [$sec(' Header', 0), $sec(' Footer', 0)]];
		return ['top' => 30, 'left' => 30, 'bottom' => 260, 'right' => 30, 'width' => 210, 'height' => 297, 'paper' => 'A4', 'orientation' => 'P', 'ishtml' => 'N', 'groups' => $groups];
	}

	/** Layout as object with everything the runner reads (missing groups / sections / properties filled in). */
	public static function layout(?string $json): object
	{
		$l = json_decode((string)$json);
		if (!is_object($l) || !isset($l->groups) || !is_array($l->groups)) $l = json_decode((string)json_encode(self::defaultLayout()));
		$def = json_decode((string)json_encode(self::defaultLayout()));
		for ($g = 0; $g < 8; $g++) {
			if (!isset($l->groups[$g]) || !is_object($l->groups[$g])) $l->groups[$g] = $def->groups[$g];
			$G = $l->groups[$g];
			$G->sortField = (string)($G->sortField ?? '');
			$G->sortBy = ($G->sortBy ?? 'a') === 'd' ? 'd' : 'a';
			if (!isset($G->sections) || !is_array($G->sections)) $G->sections = $def->groups[$g]->sections;
			foreach ($def->groups[$g]->sections as $s => $ds) if (!isset($G->sections[$s]) || !is_object($G->sections[$s])) $G->sections[$s] = $ds;
			foreach ($G->sections as $S) {
				$S->height = (float)($S->height ?? 0);
				$S->page_break = (int)($S->page_break ?? 0);
				$S->color = (string)($S->color ?? '#FFFFFF');
				if (!isset($S->objects) || !is_array($S->objects)) $S->objects = [];
				foreach ($S->objects as $o) self::normalizeObject($o);
			}
		}
		$l->paper = isset(self::PAPER[(string)($l->paper ?? '')]) ? (string)$l->paper : 'A4';
		$l->orientation = ($l->orientation ?? 'P') === 'L' ? 'L' : 'P';
		[$h, $w] = self::PAPER[$l->paper];
		if (!isset($l->height) || (float)$l->height <= 0) { $l->height = $l->orientation === 'L' ? $w : $h; $l->width = $l->orientation === 'L' ? $h : $w; }
		$l->height = (float)$l->height;
		$l->width = (float)($l->width ?? $w);
		$l->ishtml = ($l->ishtml ?? 'N') === 'Y' ? 'Y' : 'N';
		return $l;
	}

	public static function normalizeObject(object $o): void
	{
		$d = ['id' => '', 'objectType' => 'field', 'backgroundColor' => 'white', 'borderColor' => 'black', 'borderWidth' => '0', 'fieldName' => '',
			'fontColor' => 'black', 'fontFamily' => 'Helvetica', 'fontSize' => '10', 'fontWeight' => '', 'format' => '', 'textAlign' => 'left',
			'left' => 0, 'top' => 0, 'height' => 20, 'width' => 100, 'zIndex' => 0, 'minRows' => 0, 'maxRows' => 0];
		foreach ($d as $k => $v) if (!isset($o->$k)) $o->$k = $v;
		foreach (['left', 'top', 'height', 'width', 'fontSize', 'borderWidth'] as $k) $o->$k = is_numeric($o->$k) ? (float)$o->$k : (float)$d[$k];
		foreach (['minRows', 'maxRows', 'zIndex'] as $k) $o->$k = (int)$o->$k;
		foreach (['id', 'objectType', 'fieldName', 'fontFamily', 'fontWeight', 'format', 'textAlign', 'backgroundColor', 'borderColor', 'fontColor'] as $k) $o->$k = (string)$o->$k;
		if (!in_array($o->objectType, ['field', 'label', 'image'], true)) $o->objectType = 'field';
		if ($o->textAlign === '') $o->textAlign = 'left';
	}

	/** Layout sent by the designer → JSON to store (validated object, nothing else). */
	public static function layoutJson(mixed $layout): string
	{
		$l = is_string($layout) ? json_decode($layout) : json_decode((string)json_encode($layout));
		if (!is_object($l)) throw new InvalidArgumentException('Invalid layout');
		$l = self::layout((string)json_encode($l));
		unset($l->setFocus);
		// nuBuilder's designer keeps border width and font size as text ("0", "10" – core/nureportjson.js); the runner reads both
		foreach ($l->groups as $G) foreach ($G->sections as $S) foreach ($S->objects as $o) foreach (['borderWidth', 'fontSize'] as $k) $o->$k = self::numText($o->$k);
		return (string)json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * The layout to store, with every value in the JSON type it had in the stored layout ("10" stays text, 10 stays a number) – a
	 * save without a change leaves sre_layout as nuBuilder wrote it (0.8.364, dev/tests/compat.js meta:report).
	 */
	public static function keepTypes(string $newJson, string $oldJson): string
	{
		$old = json_decode($oldJson);
		$new = json_decode($newJson);
		if (!is_object($old) || !is_object($new)) return $newJson;
		$walk = static function (mixed $n, mixed $o) use (&$walk): mixed {
			if (is_object($n) && is_object($o)) { foreach (get_object_vars($n) as $k => $v) if (property_exists($o, $k)) $n->$k = $walk($v, $o->$k); return $n; }
			if (is_array($n) && is_array($o)) { foreach ($n as $i => $v) if (array_key_exists($i, $o)) $n[$i] = $walk($v, $o[$i]); return $n; }
			if (is_string($o) && (is_int($n) || is_float($n))) return self::numText($n);
			if ((is_int($o) || is_float($o)) && is_string($n) && is_numeric($n)) return $n + 0;
			return $n;
		};
		return (string)json_encode($walk($new, $old), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/** 10.0 → "10", 10.5 → "10.5", "10" stays. */
	private static function numText(mixed $v): string
	{
		return is_float($v) && floor($v) == $v && abs($v) < 1e15 ? (string)(int)$v : (string)$v;
	}

	// ================================================================= data sources

	/**
	 * What a report can print from (nuBuilder's zzzzsys_report_data): procedures that fill #TABLE_ID#, SQL builder queries, tables and views.
	 * @return array<int, array{id: string, kind: string, code: string, description: string}>
	 */
	public static function sources(): array
	{
		$db = nu_db();
		$out = [];
		foreach ($db->all("SELECT zzzzsys_php_id, sph_code, sph_description FROM zzzzsys_php WHERE sph_php LIKE '%#TABLE_ID#%' AND " . NuProcedure::listCondition() . ' ORDER BY sph_code') as $p) {
			$out[] = ['id' => 'PROCEDURE:' . $p['zzzzsys_php_id'], 'kind' => 'PROCEDURE', 'code' => (string)$p['sph_code'], 'description' => (string)$p['sph_description']];
		}
		if ($db->tableExists('zzzzsys_select')) {
			foreach ($db->all("SELECT zzzzsys_select_id, sse_code, sse_description FROM zzzzsys_select WHERE COALESCE(sse_system, '') = '' ORDER BY sse_code, sse_description") as $s) {
				$out[] = ['id' => 'SQL:' . $s['zzzzsys_select_id'], 'kind' => 'SQL', 'code' => (string)($s['sse_code'] ?: $s['zzzzsys_select_id']), 'description' => (string)$s['sse_description']];
			}
		}
		foreach (self::tables() as $t) $out[] = ['id' => 'TABLE:' . $t, 'kind' => 'TABLE', 'code' => $t, 'description' => ''];
		return $out;
	}

	/** Tables and views of the database (nuBuilder's own zzzzsys_ tables and temporary ___nu tables left out). */
	public static function tables(bool $withSystem = false): array
	{
		$db = nu_db();
		$names = match ($db->type) {
			'mysql'  => array_column($db->all('SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'), 'n'),
			'sqlsrv' => array_column($db->all('SELECT TABLE_NAME AS n FROM INFORMATION_SCHEMA.TABLES ORDER BY TABLE_NAME'), 'n'),
			default  => array_column($db->all("SELECT name AS n FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name"), 'n'),
		};
		return array_values(array_filter(array_map('strval', $names), static fn($n) => !str_starts_with($n, '_') && ($withSystem || !str_starts_with(strtolower($n), 'zzzzsys_'))));
	}

	/** "Procedure customer_list – Customers", "SQL …", "Table …" for a data source id. */
	public static function sourceLabel(string $parentId): string
	{
		[$kind, $id] = array_pad(explode(':', $parentId, 2), 2, '');
		$db = nu_db();
		try {
			if ($kind === 'PROCEDURE') {
				$p = $db->one('SELECT sph_code, sph_description FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$id]);
				return t('rpt_src_procedure') . ' ' . ($p ? trim($p['sph_code'] . ' – ' . $p['sph_description'], ' –') : $id);
			}
			if ($kind === 'SQL') {
				$s = $db->tableExists('zzzzsys_select') ? $db->one('SELECT sse_code, sse_description FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$id]) : null;
				return t('rpt_src_sql') . ' ' . ($s ? trim(($s['sse_code'] ?: '') . ' – ' . $s['sse_description'], ' –') : $id);
			}
			if ($kind === 'TABLE') return t('rpt_src_table') . ' ' . $id;
		} catch (Throwable) {
		}
		return $parentId;
	}

	/**
	 * Rows of a data source as associative arrays, with nuBuilder's criteria values: every hash variable that is not a column becomes one
	 * (so form fields of the launch form can be printed). $forFields: only the columns are needed (designer) – a SQL source is tried without
	 * its WHERE clause when it fails with empty criteria, as nuBuilder's designer does.
	 * @return array{0: string[], 1: array<int, array>} [columns, rows]
	 */
	public static function data(string $parentId, array $hash, bool $forFields = false): array
	{
		[$kind, $id] = array_pad(explode(':', $parentId, 2), 2, '');
		$db = nu_db();
		$fetch = static function (string $sql) use ($db, $forFields): array {
			$st = $db->run($sql);
			$cols = [];
			for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = (string)($st->getColumnMeta($i)['name'] ?? $i);
			$rows = [];
			if (!$forFields) while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) $rows[] = $r;
			$st->closeCursor();
			return [$cols, $rows];
		};

		if ($kind === 'PROCEDURE') {
			$php = $db->one('SELECT zzzzsys_php_id, sph_code, sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$id]);
			if (!$php) throw new RuntimeException(t('form_err_proc_missing', $id));
			$tt = '___nu1' . bin2hex(random_bytes(7)) . '___';
			$hash = ['TABLE_ID' => $tt] + $hash;
			$hash['TABLE_ID'] = $tt;
			try {
				$r = NuPhp::isolated(static fn() => NuPhp::run((string)$php['sph_php'], $hash + ['PROCEDURE_CODE' => (string)$php['sph_code']], (string)$php['sph_code']));
				if ($r['errors']) throw new RuntimeException((string)$r['errors'][0]['message']);
				if (!$db->tableExists($tt)) throw new RuntimeException(t('rpt_err_no_table', (string)$php['sph_code']));
				[$cols, $rows] = $fetch("SELECT * FROM $tt");
			} finally {
				try { $db->run($db->type === 'sqlsrv' ? "IF OBJECT_ID('$tt') IS NOT NULL DROP TABLE $tt" : "DROP TABLE IF EXISTS $tt"); } catch (Throwable) {}
			}
		} elseif ($kind === 'TABLE') {
			[$cols, $rows] = $fetch('SELECT * FROM ' . NuDB::ident($id) . ($forFields ? ' WHERE 1 = 0' : ''));
		} elseif ($kind === 'SQL') {
			$sql = $db->tableExists('zzzzsys_select') ? $db->value('SELECT sse_sql FROM zzzzsys_select WHERE zzzzsys_select_id = ?', [$id]) : null;
			if ($sql === null || trim((string)$sql) === '') throw new RuntimeException(t('rpt_err_sql_missing', $id));
			$sql = rtrim(trim(NuHash::replace((string)$sql, $hash, NuHash::SQL)), ';');
			try {
				[$cols, $rows] = $fetch($sql);
			} catch (Throwable $e) {
				if (!$forFields) throw $e;
				$flat = str_replace(["\r", "\n"], ' ', $sql);
				$w = stripos($flat, ' WHERE ');
				if ($w === false) throw $e;
				[$cols, $rows] = $fetch(substr($flat, 0, $w));
			}
		} else {
			throw new RuntimeException(t('rpt_err_source'));
		}

		if ($forFields) return [$cols, []];
		// criteria: hash variables that are not columns (nuAddCriteriaValues), at most 199 characters as in nuBuilder
		$lower = array_flip(array_map('strtolower', $cols));
		$extra = [];
		foreach ($hash as $k => $v) {
			if (!is_string($k) || $k === '' || is_array($v) || is_object($v) || isset($lower[strtolower($k)])) continue;
			$extra[$k] = mb_substr((string)$v, 0, 199);
			$lower[strtolower($k)] = true;
		}
		if ($extra) {
			$cols = array_merge($cols, array_keys($extra));
			foreach ($rows as &$r) $r += $extra;
			unset($r);
		}
		return [$cols, $rows];
	}

	/**
	 * Fields for the designer: the columns of the source (run with empty criteria, as nuBuilder's nuTTList) and the objects of the launch
	 * form (their values become columns when the report runs). @return array{columns: string[], criteria: string[]}
	 */
	public static function fields(string $parentId, string $launchFormId = ''): array
	{
		$hash = NuHash::plain();
		$criteria = [];
		if ($launchFormId !== '') {
			foreach (nu_db()->all("SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ? AND sob_all_type NOT IN ('run', 'html', 'word', 'contentbox', 'subform', 'chart', 'image') ORDER BY sob_all_order", [$launchFormId]) as $o) {
				$hash[(string)$o['sob_all_id']] = '';
				$criteria[] = (string)$o['sob_all_id'];
			}
		}
		$hash['RECORD_ID'] = '';
		[$cols] = self::data($parentId, $hash, true);
		$lower = array_flip(array_map('strtolower', $cols));
		return ['columns' => $cols, 'criteria' => array_values(array_filter($criteria, static fn($c) => !isset($lower[strtolower($c)])))];
	}

	// ================================================================= designer lists

	/** Font families: TCPDF's core fonts and the fonts in <tcpdf>/fonts (style variants b / i / bi left out). */
	public static function fonts(): array
	{
		$out = ['Helvetica', 'Courier', 'Times', 'Symbol'];
		$dir = self::tcpdfDir();
		if ($dir !== null && is_dir("$dir/fonts")) {
			$names = [];
			foreach (glob("$dir/fonts/*.php") ?: [] as $f) $names[strtolower(basename($f, '.php'))] = true;
			foreach (array_keys($names) as $n) {
				if (in_array($n, ['helvetica', 'courier', 'times', 'symbol', 'zapfdingbats'], true) || preg_match('/^(helvetica|courier|times)(b|i|bi)$/', $n)) continue;
				if (preg_match('/^(.+?)(bi|b|i)$/', $n, $m) && isset($names[$m[1]])) continue;
				$out[] = $n;
			}
		}
		return array_values(array_unique($out));
	}

	/** nuBuilder's format list: ["N|1,000.00", "1,000.00"] from zzzzsys_format. */
	public static function formats(): array
	{
		$out = [['', '']];
		try {
			foreach (nu_db()->all('SELECT srm_type, srm_format FROM zzzzsys_format ORDER BY srm_type, srm_format') as $f) {
				$out[] = [substr((string)$f['srm_type'], 0, 1) . '|' . trim((string)$f['srm_format']), trim((string)$f['srm_format'])];
			}
		} catch (Throwable) {
		}
		return $out;
	}

	/** Image files of zzzzsys_file: [code, description, id]. */
	public static function images(): array
	{
		try {
			return array_map(static fn($r) => [(string)$r['sfi_code'], (string)$r['sfi_description'], (string)$r['zzzzsys_file_id']],
				nu_db()->all("SELECT zzzzsys_file_id, sfi_code, sfi_description FROM zzzzsys_file WHERE sfi_json LIKE '%image%' ORDER BY sfi_code"));
		} catch (Throwable) {
			return [];
		}
	}

	// ================================================================= TCPDF

	/** nuBuilder's TCPDF: $nuConfigTCPDFPath, nuBuilder Next's third_party/tcpdf, or <nuBuilder>/third_party/tcpdf. */
	public static function tcpdfDir(): ?string
	{
		static $dir = false;
		if ($dir !== false) return $dir;
		$cfg = trim((string)nu_cfg('nuConfigTCPDFPath'));
		foreach ([$cfg !== '' ? NuBuilderLib::absPath($cfg) : '', NU_ROOT . '/third_party/tcpdf', NuBuilderLib::path() . '/third_party/tcpdf'] as $d) {
			if ($d !== '' && is_file("$d/tcpdf.php")) return $dir = rtrim($d, '/\\');
			// TCPDF 7 (0.8.232): a Composer project folder – vendor/tecnickcom/tcpdf + tc-lib-pdf and its libraries
			if ($d !== '' && is_file("$d/vendor/tecnickcom/tcpdf/tcpdf.php")) return $dir = rtrim($d, '/\\') . '/vendor/tecnickcom/tcpdf';
		}
		return $dir = null;
	}

	public static function tcpdfVersion(): string
	{
		$d = self::tcpdfDir();
		return $d !== null && is_file("$d/VERSION") ? trim((string)file_get_contents("$d/VERSION")) : '';
	}

	/**
	 * Composer autoloader of TCPDF 7 ('' = TCPDF 6, which needs none). TCPDF 7 is only a facade over tc-lib-pdf (class
	 * Com\Tecnick\Pdf\Tcpdf) and about a dozen tc-lib-* libraries – without their autoloader: "Class … not found".
	 */
	public static function tcpdfAutoload(string $dir): string
	{
		if (basename(dirname($dir)) === 'tecnickcom' && is_file(dirname($dir, 2) . '/autoload.php')) return dirname($dir, 2) . '/autoload.php';
		return is_file("$dir/vendor/autoload.php") ? "$dir/vendor/autoload.php" : '';
	}

	/** Loads TCPDF once; exceptions instead of die() on errors. */
	public static function loadTcpdf(): void
	{
		if (class_exists('TCPDF', false)) return;
		$dir = self::tcpdfDir();
		if ($dir === null) throw new RuntimeException(t('rpt_err_tcpdf'));
		$autoload = self::tcpdfAutoload($dir);
		if (str_starts_with(self::tcpdfVersion(), '7') || is_file(dirname($dir) . '/tc-lib-pdf/composer.json')) {
			if (PHP_VERSION_ID < 80200) throw new RuntimeException(t('rpt_err_tcpdf7_php', PHP_VERSION));
			if ($autoload === '' || !is_dir(dirname($dir) . '/tc-lib-pdf')) throw new RuntimeException(t('rpt_err_tcpdf7_deps'));
		}
		if ($autoload !== '') require_once $autoload;
		$fonts = $dir . '/fonts/';
		if (is_dir(dirname($dir) . '/tc-lib-pdf-font/target/fonts')) $fonts = dirname($dir) . '/tc-lib-pdf-font/target/fonts/';    // TCPDF 7
		if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
		if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
			define('K_TCPDF_EXTERNAL_CONFIG', true);
			define('K_PATH_MAIN', $dir . '/');
			define('K_PATH_URL', '');
			define('K_PATH_FONTS', $fonts);
			define('K_PATH_IMAGES', $dir . '/');
			define('K_BLANK_IMAGE', '_blank.png');
			define('PDF_PAGE_FORMAT', 'A4');
			define('PDF_PAGE_ORIENTATION', 'P');
			define('PDF_CREATOR', 'nuBuilder Next');
			define('PDF_AUTHOR', 'nuBuilder Next');
			define('PDF_UNIT', 'mm');
			define('PDF_MARGIN_HEADER', 0);
			define('PDF_MARGIN_FOOTER', 0);
			define('PDF_MARGIN_TOP', 0);
			define('PDF_MARGIN_BOTTOM', 0);
			define('PDF_MARGIN_LEFT', 0);
			define('PDF_MARGIN_RIGHT', 0);
			define('PDF_FONT_NAME_MAIN', 'helvetica');
			define('PDF_FONT_SIZE_MAIN', 10);
			define('PDF_FONT_NAME_DATA', 'helvetica');
			define('PDF_FONT_SIZE_DATA', 8);
			define('PDF_FONT_MONOSPACED', 'courier');
			define('PDF_IMAGE_SCALE_RATIO', 1.25);
			define('HEAD_MAGNIFICATION', 1.1);
			define('K_CELL_HEIGHT_RATIO', 1.25);
			define('K_TITLE_MAGNIFICATION', 1.3);
			define('K_SMALL_RATIO', 2 / 3);
			define('K_THAI_TOPCHARS', true);
			define('K_TCPDF_CALLS_IN_HTML', false);
			define('K_TIMEZONE', date_default_timezone_get());
		}
		require_once $dir . '/tcpdf.php';
		require_once NU_ROOT . '/core/nutcpdf.php';
	}

	// ================================================================= running

	/** Hash of a report run: the caller's hash, date variables (nuAddToHashList), code and description. */
	public static function runHash(array $r, array $hash): array
	{
		$now = new DateTime();
		return ['code' => (string)$r['sre_code'], 'description' => (string)$r['sre_description'], 'parentID' => (string)$r['sre_zzzzsys_php_id'],
			'nu_date_time' => $now->format('Y-m-d H:i:s'), 'nu_date' => $now->format('Y-m-d'), 'nu_time' => $now->format('H:i:s'),
			'nu_year' => $now->format('Y'), 'nu_month' => $now->format('m'), 'nu_day' => $now->format('d'), 'nu_hour' => $now->format('H'), 'nu_minute' => $now->format('i')]
			+ $hash;
	}

	/** Runs the report and returns the PDF as a string. $layoutJson: an unsaved layout (designer preview). */
	public static function pdf(array $r, array $hash, ?string $layoutJson = null): string
	{
		self::loadTcpdf();
		require_once NU_ROOT . '/core/form/nuphp_api.php';      // nuAddFormatting, nuHex2rgb (and what data source procedures use)
		$hash = self::runHash($r, $hash);
		[, $rows] = self::data((string)$r['sre_zzzzsys_php_id'], $hash);
		$layout = self::layout($layoutJson ?? (string)$r['sre_layout']);
		$engine = new NuReportEngine($layout, $rows, $hash, (string)$r['sre_code'], (string)$r['sre_description']);
		return $engine->run();
	}

	/** Remembers a run (report + hash) in the session; the PDF is made by ?p=reportpdf&i=<token> (nuBuilder: nurunpdf.php?i=). */
	public static function token(array $r, array $hash): string
	{
		$t = bin2hex(random_bytes(12));
		$list = $_SESSION['nu_report_runs'] ?? [];
		$list[$t] = ['id' => (string)$r['zzzzsys_report_id'], 'hash' => $hash, 'time' => time()];
		if (count($list) > 30) $list = array_slice($list, -30, null, true);
		$_SESSION['nu_report_runs'] = $list;
		return $t;
	}

	public static function fromToken(string $t): ?array
	{
		$run = $_SESSION['nu_report_runs'][$t] ?? null;
		if (!is_array($run) || time() - (int)$run['time'] > 6 * 3600) return null;
		$r = self::load((string)$run['id']);
		return $r ? ['report' => $r, 'hash' => (array)$run['hash']] : null;
	}

	/** Folder for nuRunReportSave (nuBuilder: <nuBuilder>/temp): <nuBuilder Next>/temp, protected from the web. */
	public static function tempDir(): string
	{
		$dir = NU_ROOT . '/temp';
		NuSecurity::protectDir($dir);                        // also replaces the .htaccess of older versions (Require + Deny → 500 on Apache 2.4)
		return $dir;
	}

	/**
	 * nuRunReportSave: writes the PDF into the temp folder and records it in nuBuilder's pdf_temp table.
	 * @return array{id: ?string, filename: ?string}
	 */
	public static function saveFile(string $pdf, string $code, string $tag, string $fileName): array
	{
		if (NuDemo::active()) return ['id' => null, 'filename' => null];
		$name = trim($fileName) === '' ? 'nupdf_' . nu_id() . '.pdf' : preg_replace('/[^\w.\-]+/u', '_', basename($fileName));
		if (!str_ends_with(strtolower($name), '.pdf')) $name .= '.pdf';
		$dir = self::tempDir();
		if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException(t('rpt_err_temp', $dir));
		$path = $dir . '/' . $name;
		file_put_contents($path, $pdf);
		$db = nu_db();
		$id = nu_id();
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		if (!$db->tableExists('pdf_temp')) {
			$db->run('CREATE TABLE pdf_temp (pdf_temp_id ' . $T('id') . ' NOT NULL PRIMARY KEY, pdf_added_by ' . $T('id') . ' NULL, pdf_code ' . $T('text', '100') . ' NULL, '
				. 'pdf_tag ' . $T('text', '100') . ' NULL, pdf_file_name ' . $T('text', '255') . ' NULL, pdf_created ' . $T('datetime') . ' NULL)' . ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
		}
		$db->insert('pdf_temp', ['pdf_temp_id' => $id, 'pdf_added_by' => (string)(NuAuth::user()['id'] ?? ''), 'pdf_code' => mb_substr($code, 0, 100),
			'pdf_tag' => mb_substr($tag, 0, 100), 'pdf_file_name' => $path, 'pdf_created' => date($db->type === 'sqlsrv' ? 'Y-m-d\TH:i:s' : 'Y-m-d H:i:s')]);
		return ['id' => $id, 'filename' => $path];
	}

	/** Sends the PDF to the browser (inline, or as download) and ends the request. */
	public static function send(string $pdf, string $fileName, bool $download = false): never
	{
		while (ob_get_level() > 0) ob_end_clean();
		header_remove('X-Frame-Options');
		header('X-Frame-Options: SAMEORIGIN');
		header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
		header('Content-Type: application/pdf');
		header('Content-Length: ' . strlen($pdf));
		header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^\w.\-]+/', '_', $fileName) . '.pdf"');
		header('Cache-Control: private, no-store');
		echo $pdf;
		exit;
	}

	/** An error page that also works inside an iframe. */
	public static function fail(string $message, int $status = 500): never
	{
		while (ob_get_level() > 0) ob_end_clean();
		http_response_code($status);
		header_remove('X-Frame-Options');
		header('X-Frame-Options: SAMEORIGIN');
		header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'");
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><meta charset="utf-8"><title>' . h(t('rpt_title')) . '</title><body style="font:14px system-ui,sans-serif;margin:0;padding:1rem;color:#842029;background:#f8d7da">'
			. h($message) . '</body>';
		exit;
	}
}
