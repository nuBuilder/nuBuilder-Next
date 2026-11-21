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

	/** The POST actions of pages/nudebug.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$db = $nuIn['db'] ?? null; $T = $nuIn['T'] ?? null;
		if (nu_is_post()) {
			NuAcl::require(NuAcl::FORM_DEBUG, 'delete');
			$action = nu_post('action');
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
}
