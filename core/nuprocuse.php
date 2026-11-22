<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Where a procedure is used: run buttons, Select / display procedures and lookup PHP of objects, report sources, dashboard widgets,
 * start page tiles (exact), and calls in code
 * – nuRunPHP…('code'), nuProcedure('code'), nuEval('id') in form JavaScript, events, HTML objects, form hooks, other procedures, Global
 * code … (found by text, so a code built at run time is missed). Form hooks (<form>_BS …) and the global hooks (nu_before_save …) are
 * marked as hooks instead. One pass per request (NuMemo), shared by the procedures list, the procedure page and the delete check.
 */
final class NuProcUse
{
	public const CALL_RE = '~\b(?:nuRunPHP\w*|nuProcedure|nuEval)\s*\(\s*([\'"])([^\'"\r\n]{1,120})\1~';

	/**
	 * @return array<string, array{forms: array<string, string>, places: list<array{kind: string, label: string, url: string, form: string, exact: bool}>, code: bool, hook: string}>
	 *   by procedure id: forms = form id => code, places = every use, code = some use was found only in code, hook = '' | form | global
	 */
	public static function all(): array
	{
		return NuMemo::get('procuse', ['zzzzsys_php', 'zzzzsys_object', 'zzzzsys_form', 'zzzzsys_event', 'zzzzsys_report', 'zzzzsys_nunext_list_view', 'zzzzsys_nunext_widget'],
			static fn(): array => self::build());
	}

	/** The uses of one procedure (empty entry when none). */
	public static function of(string $id): array
	{
		return self::all()[$id] ?? ['forms' => [], 'places' => [], 'code' => false, 'hook' => self::hookOf($id, '')];
	}

	/** Ids of procedures that are used / unused / hooks – for the list filter. @return list<string> */
	public static function ids(string $which): array
	{
		$out = [];
		foreach (self::all() as $id => $u) {
			$is = $u['hook'] !== '' ? 'hook' : ($u['places'] ? 'used' : 'unused');
			if ($is === $which) $out[] = (string)$id;
		}
		return $out;
	}

	private static function hookOf(string $id, string $code): string
	{
		if (in_array(substr($id, -3), NuProcedure::HOOKS, true)) return 'form';
		return in_array($code, NuProcedure::HOOK_CODES, true) ? 'global' : '';
	}

	private static function build(): array
	{
		$db = nu_db();
		if (!$db->tableExists('zzzzsys_php')) return [];
		$out = [];
		$byCode = [];
		foreach ($db->all('SELECT zzzzsys_php_id, sph_code FROM zzzzsys_php') as $p) {
			$id = (string)$p['zzzzsys_php_id'];
			$out[$id] = ['forms' => [], 'places' => [], 'code' => false, 'hook' => self::hookOf($id, (string)$p['sph_code'])];
			if ((string)$p['sph_code'] !== '') $byCode[(string)$p['sph_code']] = $id;
		}
		$resolve = static fn(string $v): string => isset($out[$v]) ? $v : ($byCode[$v] ?? '');
		$seen = [];
		$add = static function (string $pid, string $form, string $kind, string $label, string $url, bool $exact) use (&$out, &$seen): void {
			if ($pid === '' || !isset($out[$pid])) return;
			$k = $pid . '|' . $form . '|' . $kind . '|' . $label;
			if (isset($seen[$k])) return;
			$seen[$k] = true;
			$out[$pid]['places'][] = ['kind' => $kind, 'label' => $label, 'url' => $url, 'form' => $form, 'exact' => $exact];
			if ($form !== '') $out[$pid]['forms'][$form] = (string)(nu_form($form)['code'] ?? $form);
		};
		// run buttons, Select / display procedures and lookup PHP of objects
		if ($db->tableExists('zzzzsys_object')) {
			$cols = $db->columns('zzzzsys_object');
			$refs = array_values(array_filter(['sob_select_procedure', 'sob_display_procedure', 'sob_lookup_php'], static fn($c) => isset($cols[$c])));
			$want = array_merge(['zzzzsys_object_id', 'sob_all_id', 'sob_all_zzzzsys_form_id', 'sob_all_type', 'sob_run_zzzzsys_form_id'], $refs);
			$where = "sob_all_type = 'run'" . implode('', array_map(static fn($c) => " OR COALESCE($c, '') <> ''", $refs));
			foreach ($db->all('SELECT ' . implode(', ', $want) . ' FROM zzzzsys_object WHERE ' . $where) as $o) {
				$f = (string)$o['sob_all_zzzzsys_form_id'];
				$url = NuCodeSearch::designUrl($f, ['obj' => (string)$o['zzzzsys_object_id']]);
				$label = $o['sob_all_id'] . ' (' . $o['sob_all_type'] . ')';
				if ((string)$o['sob_all_type'] === 'run') $add($resolve((string)($o['sob_run_zzzzsys_form_id'] ?? '')), $f, 'run', $label, $url, true);
				foreach ($refs as $c) if ((string)($o[$c] ?? '') !== '') $add($resolve((string)$o[$c]), $f, 'object', $label, $url, true);
			}
		}
		// report sources
		if ($db->tableExists('zzzzsys_report')) {
			foreach ($db->all('SELECT zzzzsys_report_id, sre_code, sre_zzzzsys_php_id FROM zzzzsys_report') as $r) {
				$src = (string)$r['sre_zzzzsys_php_id'];
				if (str_starts_with($src, 'PROCEDURE:')) $src = substr($src, 10);
				$add($resolve($src), '', 'report', (string)$r['sre_code'], nu_url('reports', ['id' => (string)$r['zzzzsys_report_id']]), true);
			}
		}
		// dashboard widgets with a procedure as source
		if ($db->tableExists('zzzzsys_nunext_widget') && $db->tableExists('zzzzsys_nunext_dashboard')) {
			foreach ($db->all("SELECT w.title, w.query, d.zzzzsys_nunext_dashboard_id AS did, d.title AS dtitle FROM zzzzsys_nunext_widget w
				JOIN zzzzsys_nunext_dashboard d ON d.zzzzsys_nunext_dashboard_id = w.dashboard_id WHERE w.source = 'php'") as $w) {
				$add($resolve(trim((string)$w['query'])), '', 'widget', trim($w['dtitle'] . ' · ' . $w['title'], ' ·'), nu_url('dashboards', ['id' => (string)$w['did']]), true);
			}
		}
		// start page tiles of the kind procedure (saved layouts)
		if ($db->tableExists('zzzzsys_nunext_list_view')) {
			foreach ($db->all("SELECT list_key, name, definition FROM zzzzsys_nunext_list_view WHERE list_key IN ('home', 'home_user')") as $l) {
				$def = json_decode((string)$l['definition'], true);
				if (!is_array($def)) continue;
				$label = t('prc_use_tile') . ' · ' . (string)$l['name'];
				$stack = [$def];
				while ($stack) {
					$x = array_pop($stack);
					if (($x['kind'] ?? null) === 'procedure' && is_scalar($x['target'] ?? null)) $add($resolve((string)$x['target']), '', 'tile', $label, nu_url('home'), true);
					foreach ($x as $v) if (is_array($v)) $stack[] = $v;
				}
			}
		}
		// calls in code
		try {
			foreach (NuCodeSearch::sources(['drafts' => false, 'pages' => false]) as $s) {
				$code = (string)$s['code'];
				if (!str_contains($code, 'nuRunPHP') && !str_contains($code, 'nuProcedure') && !str_contains($code, 'nuEval')) continue;
				if (!preg_match_all(self::CALL_RE, $code, $m)) continue;
				foreach (array_unique($m[2]) as $name) {
					$pid = $resolve((string)$name);
					if ($pid === '' || (string)($s['proc'] ?? '') === $pid) continue;
					$form = (string)($s['form'] ?? '');
					$label = $form !== '' ? (string)$s['label'] : (string)$s['gtitle'] . ' · ' . (string)$s['label'];
					$before = count($out[$pid]['places']);
					$add($pid, $form, 'code', $label, (string)($s['url'] ?? ''), false);
					if (count($out[$pid]['places']) > $before) $out[$pid]['code'] = true;
				}
			}
		} catch (Throwable $ig) {
			nu_ignored($ig, 'code places for the uses of procedures');
		}
		return $out;
	}
}
