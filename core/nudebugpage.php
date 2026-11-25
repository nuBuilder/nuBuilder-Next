<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nudebug.php (code review R7: a page defines no global function). */
final class NuDebugPage
{
	/** Message as text: nuBuilder's "<br>" after the header line is dropped, " <html>" messages are shown as source. */
	public static function text(string $m): string
	{
		$m = preg_replace("/\n\n<br>\n/", "\n", $m, 1) ?? $m;
		// nuBuilder Forte writes a little HTML (<b>nuRunQuery</b>, <br>) – shown as text it read as raw tags (review D8)
		$m = preg_replace('~<br\s*/?>~i', "\n", $m) ?? $m;
		return trim(preg_replace('~</?(b|i|strong|em|u|pre|code)>~i', '', $m) ?? $m);   // only these tags: SQL in the text may hold "<" 
	}

	/** Deleted rows kept in the session for Undo up to this size (JSON); a larger "Delete all" asks first. */
	public const UNDO_MAX = 4000000;

	/** The POST actions of pages/nudebug.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$db = $nuIn['db'] ?? null; $T = $nuIn['T'] ?? null;
		if (nu_is_post()) {
			NuAcl::require(NuAcl::FORM_DEBUG, 'delete');
			$action = nu_post('action');
			if (nu_post('json') === '1') self::json($db, $T, $action);   // assets/js/nudebug.js: delete without asking, Undo
			$n = 0;
			if ($action === 'delete_selected') {
				$ids = array_values(array_filter((array)($_POST['ids'] ?? []), 'is_string'));
				foreach (array_chunk($ids, 200) as $chunk) {
					$n += $db->run("DELETE FROM $T WHERE zzzzsys_debug_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)->rowCount();
				}
			} elseif ($action === 'delete_all') {
				$n = $db->run("DELETE FROM $T")->rowCount();
			} elseif ($action === 'delete_one') {
				$n = $db->delete($T, 'zzzzsys_debug_id', nu_post('id'));
			}
			NuLog::write('debug_deleted', $T, '', ['rows' => $n]);
			nu_flash('success', t('debug_deleted', $n));
			nu_redirect(nu_url('debug', array_intersect_key($_GET, array_flip(['q', 'flag', 'user', 'from', 'to']))));
		}
		return compact('db', 'T');
	}

	/**
	 * Delete without a question and Undo (assets/js/nudebug.js): the deleted rows are kept in the session (NuSess::DEBUG_UNDO) – Undo
	 * writes them back. ids = one row, the selected rows; all = every row (more than UNDO_MAX of text: {confirm: true} unless force).
	 */
	private static function json(NuDB $db, string $T, string $action): never
	{
		if ($action === 'undo') {
			$rows = $_SESSION[NuSess::DEBUG_UNDO] ?? [];
			unset($_SESSION[NuSess::DEBUG_UNDO]);
			$n = 0;
			foreach (is_array($rows) ? $rows : [] as $r) {
				if (!is_array($r) || !isset($r['zzzzsys_debug_id'])) continue;
				if ($db->value("SELECT COUNT(*) FROM $T WHERE zzzzsys_debug_id = ?", [$r['zzzzsys_debug_id']])) continue;   // written again meanwhile
				$db->insert($T, $r);
				$n++;
			}
			NuLog::write('debug_restored', $T, '', ['rows' => $n]);
			nu_json(['ok' => true, 'n' => $n, 'message' => t('debug_restored', $n)]);
		}
		if ($action === 'delete_all') {
			$len = $db->type === 'sqlsrv' ? 'DATALENGTH' : 'LENGTH';
			$size = (int)$db->value("SELECT COALESCE(SUM($len(deb_message)), 0) FROM $T");
			$keep = $size <= self::UNDO_MAX;
			if (!$keep && nu_post('force') !== '1') nu_json(['ok' => false, 'confirm' => true, 'message' => t('debug_delete_all_confirm')]);
			$rows = $keep ? $db->all("SELECT * FROM $T") : [];
			$n = $db->run("DELETE FROM $T")->rowCount();
		} else {
			$ids = array_values(array_filter((array)($_POST['ids'] ?? []), static fn($v) => is_string($v) && $v !== ''));
			$rows = []; $n = 0;
			foreach (array_chunk(array_slice($ids, 0, 2000), 200) as $chunk) {
				$in = implode(',', array_fill(0, count($chunk), '?'));
				$rows = array_merge($rows, $db->all("SELECT * FROM $T WHERE zzzzsys_debug_id IN ($in)", $chunk));
				$n += $db->run("DELETE FROM $T WHERE zzzzsys_debug_id IN ($in)", $chunk)->rowCount();
			}
			$keep = strlen((string)json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE)) <= self::UNDO_MAX;
		}
		if ($keep && $rows) $_SESSION[NuSess::DEBUG_UNDO] = $rows; else unset($_SESSION[NuSess::DEBUG_UNDO]);
		NuLog::write('debug_deleted', $T, '', ['rows' => $n]);
		nu_json(['ok' => true, 'n' => $n, 'undo' => $keep && $rows !== [], 'message' => t('debug_deleted', $n)]);
	}
}
