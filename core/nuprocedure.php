<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Procedures (zzzzsys_php) – nuBuilder's form "nuprocedure": PHP code run by run objects, nuRunPHP / nuRunPHPHidden.
 * Form events (<form id>_BB … _AD) and lookup After Browse code (<object id>_AB) live in the same table but are edited in
 * the form builder; they are not listed here. System procedures (sph_system = 1) only show in developer mode.
 * Rights: form "nuprocedure". Who may run a procedure: zzzzsys_access_php, sph_global = 1 (everyone).
 */
final class NuProcedure
{
	public const TABLE = 'zzzzsys_php';
	public const HOOKS = ['_BB', '_BE', '_BS', '_AS', '_BD', '_AD', '_AB'];
	/** Codes of nuBuilder's global procedures (run by nuBuilder at these events) – suggestions for the code field. */
	public const HOOK_CODES = ['nu_before_save', 'nu_after_save', 'nu_before_edit', 'nu_before_browse', 'nu_before_delete', 'nu_after_delete',
		'nu_startup', 'nu_check_password_policy', 'nu_invalid_login', 'nu_after_update', 'nu_send_welcome_email', 'nu_debug_result_added', 'nu_home', 'nu_home_user'];
	public const STATUS = ['1' => 'proc_status_active', '0' => 'proc_status_draft', '3' => 'proc_status_archived'];
	public const RUN = ['' => 'proc_run_default', 'hide' => 'proc_run_hidden', 'window' => 'proc_run_window'];
	/** Fields compared for the change history. */
	public const FIELDS = ['sph_code', 'sph_description', 'sph_group', 'sph_category', 'sph_status', 'sph_zzzzsys_form_id', 'sph_run',
		'sph_php', 'sph_javascript', 'sph_javascript_active', 'sph_global', 'sph_demo'];

	/**
	 * nuBuilder's global hook procedures (gap analysis B5) – run where nuBuilder 4.9 runs them: nu_before_save (before Before
	 * Browse and before Before Save), nu_before_edit (before Before Edit), nu_before_delete (before Before Delete), nu_startup (after a
	 * login), nu_invalid_login (after a failed one), nu_check_password_policy (password changes), nu_after_update (after an update),
	 * nu_debug_result_added (after a Debug result). Like nuBuilder's eval(nuProcedure()): the procedure with that code, without
	 * procedure rights or status, in the request's PHP context (its errors and JavaScript join the request). nu_after_save,
	 * nu_before_browse and nu_after_delete are only reserved names, in nuBuilder too.
	 * @return bool false = the hook reported an error (nuDisplayError …) or set $error (text in NuPhp::$error)
	 */
	public static function hook(string $code, array $hash, array $vars = []): bool
	{
		static $running = [];
		if (isset($running[$code])) return true;              // a hook that triggers itself (nuDebug in nu_debug_result_added …)
		try {
			if (!nu_db()->tableExists('zzzzsys_php')) return true;
			$php = nu_db()->value('SELECT sph_php FROM zzzzsys_php WHERE sph_code = ?', [$code]);
		} catch (Throwable) {
			return true;
		}
		if ($php === null || trim((string)$php) === '') return true;
		$running[$code] = true;
		try {
			$before = count(NuPhp::$errors);
			$ok = NuPhp::run((string)$php, $hash + ['PROCEDURE_CODE' => $code], $code, $vars);
			return $ok && count(NuPhp::$errors) === $before && NuPhp::$error === '';
		} finally {
			unset($running[$code]);
		}
	}

	public static function isHook(string $id): bool
	{
		return in_array(substr($id, -3), self::HOOKS, true);
	}

	/** SQL condition: real procedures (no form events), system ones only in developer mode. */
	public static function listCondition(string $alias = ''): string
	{
		$a = $alias !== '' ? $alias . '.' : '';
		$noHook = 'RIGHT(' . $a . "zzzzsys_php_id, 3) NOT IN ('" . implode("', '", self::HOOKS) . "')";
		return NuHash::devMode() ? $noHook : "($noHook AND COALESCE({$a}sph_system, '') <> '1')";
	}

	public static function load(string $id): ?array
	{
		$p = nu_db()->one('SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$id]);
		return $p && !self::isHook($id) ? $p : null;
	}

	/** Templates (sph_template = 1) can only be changed in developer mode – clone them instead (as nuBuilder). */
	public static function locked(array $p): bool
	{
		return ((string)($p['sph_template'] ?? '') === '1' || ((string)($p['sph_system'] ?? '') === '1')) && !NuHash::devMode();
	}

	/**
	 * Validates and saves. $in: the FIELDS. @return array{0: string, 1: array} [id, errors]
	 */
	public static function save(array $in, string $id = ''): array
	{
		$db = nu_db();
		$errors = [];
		$row = [];
		foreach (['sph_code' => 300, 'sph_description' => 300, 'sph_group' => 300, 'sph_category' => 300] as $k => $len) {
			$row[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, $len);
		}
		$row['sph_status'] = array_key_exists((string)($in['sph_status'] ?? '1'), self::STATUS) ? (string)($in['sph_status'] ?? '1') : '1';
		$row['sph_run'] = array_key_exists((string)($in['sph_run'] ?? ''), self::RUN) ? (string)($in['sph_run'] ?? '') : '';
		$row['sph_zzzzsys_form_id'] = trim((string)($in['sph_zzzzsys_form_id'] ?? ''));
		$row['sph_php'] = str_replace("\r\n", "\n", (string)($in['sph_php'] ?? ''));
		$row['sph_javascript'] = str_replace("\r\n", "\n", (string)($in['sph_javascript'] ?? ''));
		foreach (['sph_javascript_active', 'sph_global', 'sph_demo'] as $k) $row[$k] = !empty($in[$k]) ? '1' : '0';

		if ($row['sph_code'] === '') $errors['sph_code'] = t('proc_err_code');
		elseif (!preg_match('/^[A-Za-z0-9_\-.]+$/', $row['sph_code'])) $errors['sph_code'] = t('proc_err_code_chars');
		elseif (self::isHook($row['sph_code'])) $errors['sph_code'] = t('proc_err_code_hook');
		elseif ($db->value('SELECT COUNT(*) FROM zzzzsys_php WHERE (sph_code = ? OR zzzzsys_php_id = ?) AND zzzzsys_php_id <> ?',
			[$row['sph_code'], $row['sph_code'], $id])) $errors['sph_code'] = t('proc_err_code_taken');
		if ($row['sph_description'] === '') $errors['sph_description'] = t('proc_err_description');
		if (trim($row['sph_php']) === '') $errors['sph_php'] = t('proc_err_php');
		if ($row['sph_zzzzsys_form_id'] !== '' && !nu_form_exists((string)$row['sph_zzzzsys_form_id'])) $errors['sph_zzzzsys_form_id'] = t('form_err_not_found');
		if ($errors) return [$id, $errors];

		$cols = $db->columns('zzzzsys_php');
		$row = array_intersect_key($row, $cols);
		if ($id !== '') {
			// NULL columns of nuBuilder Forte's row stay NULL while the form shows their default
			$row = NuDB::keepNull($db->one('SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$id]), $row,
				['sph_status' => '1', 'sph_javascript_active' => '0', 'sph_global' => '0', 'sph_demo' => '0', 'sph_run' => '']);
			$db->update('zzzzsys_php', $row, 'zzzzsys_php_id', $id);
			return [$id, []];
		}
		$id = nu_id();
		$db->insert('zzzzsys_php', array_intersect_key(['zzzzsys_php_id' => $id] + $row + ['sph_system' => '0', 'sph_template' => '0', 'sph_hide' => ''], $cols));
		return [$id, []];
	}

	/** Changed fields [name => [old, new]] for the history. */
	public static function diff(?array $old, array $new): array
	{
		$out = [];
		foreach (self::FIELDS as $f) {
			if (!array_key_exists($f, $new)) continue;
			$o = (string)($old[$f] ?? '');
			$n = (string)$new[$f];
			if (in_array($f, ['sph_javascript_active', 'sph_global', 'sph_demo'], true)) { $o = $o === '1' ? '1' : '0'; $n = $n === '1' ? '1' : '0'; }
			if ($o !== $n) $out[$f] = [$old === null ? null : $o, $n];
		}
		return $out;
	}

	/** Access levels with [id, code, description, granted]. */
	public static function access(string $id): array
	{
		$db = nu_db();
		$granted = $id !== '' ? array_flip(array_column($db->all('SELECT slp_zzzzsys_access_id FROM zzzzsys_access_php WHERE slp_zzzzsys_php_id = ?', [$id]), 'slp_zzzzsys_access_id')) : [];
		$out = [];
		foreach (NuAcl::levelsByGroup() as $a) {
			$a = ['zzzzsys_access_id' => (string)$a['zzzzsys_access_id'], 'sal_code' => $a['sal_code'], 'sal_description' => $a['sal_description'], 'sal_group' => $a['sal_group'] ?? null];
			$out[] = $a + ['granted' => isset($granted[$a['zzzzsys_access_id']])];
		}
		return $out;
	}

	/** Sets the access levels that may run the procedure. @return array{added: string[], removed: string[]} access level codes */
	public static function setAccess(string $id, array $accessIds): array
	{
		$db = nu_db();
		$codes = NuAcl::levelCodes();
		$want = array_values(array_intersect(array_map('strval', $accessIds), array_keys($codes)));
		$have = array_column($db->all('SELECT slp_zzzzsys_access_id FROM zzzzsys_access_php WHERE slp_zzzzsys_php_id = ?', [$id]), 'slp_zzzzsys_access_id');
		$add = array_diff($want, $have);
		$del = array_diff($have, $want);
		foreach ($add as $a) $db->insert('zzzzsys_access_php', ['zzzzsys_access_php_id' => nu_id(), 'slp_zzzzsys_access_id' => $a, 'slp_zzzzsys_php_id' => $id]);
		foreach ($del as $a) $db->run('DELETE FROM zzzzsys_access_php WHERE slp_zzzzsys_php_id = ? AND slp_zzzzsys_access_id = ?', [$id, $a]);
		return ['added' => array_map(static fn($a) => (string)($codes[$a] ?? $a), array_values($add)), 'removed' => array_map(static fn($a) => (string)($codes[$a] ?? $a), array_values($del))];
	}

	/**
	 * Where the procedure is used: run objects / select / display / lookup objects, and code that names it
	 * (nuRunPHP('code') … in form JavaScript, events, other procedures). [['kind', 'form' => code, 'where' => …], …]
	 */
	/**
	 * Where a procedure is used (NuProcUse): objects (run buttons, Select / display procedures, lookup PHP), reports, start page tiles
	 * and calls in code. @return list<array{kind: string, form_id: string, form: string, where: string, url: string, report_id?: string}>
	 */
	public static function usage(string $id, string $code): array
	{
		$out = [];
		foreach (NuProcUse::of($id)['places'] as $p) {
			$kind = $p['kind'] === 'run' ? 'object' : $p['kind'];
			$out[] = ['kind' => $kind, 'form_id' => $p['form'], 'form' => $p['form'] !== '' ? (string)(nu_form($p['form'])['code'] ?? $p['form']) : '',
				'where' => $p['label'], 'url' => $p['url']];
		}
		usort($out, static fn($a, $b) => [$a['kind'] === 'code', $a['form'], $a['where']] <=> [$b['kind'] === 'code', $b['form'], $b['where']]);
		return $out;
	}

	/** Change history from nuBuilder Next's log. */
	public static function history(string $id, int $limit = 100): array
	{
		NuSchema::ensure();
		return nu_db()->page("SELECT log_time, login, event, details FROM zzzzsys_nunext_log WHERE object = 'zzzzsys_php' AND record_id = ?", [$id], 'log_time DESC, zzzzsys_nunext_log_id DESC', $limit, 0);
	}

	/**
	 * Runs a saved procedure from the editor (test). $props: properties set by nuOnProcedureRun() in the browser.
	 * @return array{ok: bool, output: string, errors: array, js: array, debug: array}
	 */
	public static function run(array $p, array $props): array
	{
		$hash = NuHash::plain($props, ['FORM_ID' => (string)($p['sph_zzzzsys_form_id'] ?? ''), 'RECORD_ID' => '', 'PROCEDURE_CODE' => (string)$p['sph_code']]);
		NuPhp::reset();
		$r = NuPhp::procedure((string)$p['sph_code'], $hash);
		if (!$r['ok']) return ['ok' => false, 'output' => '', 'errors' => [['message' => (string)$r['error'], 'field' => '']], 'js' => [], 'debug' => []];
		$res = NuPhp::result();
		return ['ok' => !$res['errors'], 'output' => NuPhp::$output, 'errors' => $res['errors'], 'js' => $res['js'], 'debug' => NuPhp::$debug];
	}
}
