<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuformaccess.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuFormaccessPage
{
	/** The POST actions of pages/nuformaccess.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$canSave = $nuIn['canSave'] ?? null;
		$out = $nuIn['out'] ?? null;
		$post = $nuIn['post'] ?? null;
		$fid = $nuIn['fid'] ?? null;
		$rowsOf = $nuIn['rowsOf'] ?? null;
		$hasDm = $nuIn['hasDm'] ?? null;
		$hasFt = $nuIn['hasFt'] ?? null;
		$restricted = $nuIn['restricted'] ?? null;
		$adminForm = $nuIn['adminForm'] ?? null;
		$db = $nuIn['db'] ?? null;
		$form = $nuIn['form'] ?? null;
		$col = $nuIn['col'] ?? null;
		if (nu_is_post()) {
			if (!$canSave) $out(['ok' => false, 'message' => t('err_forbidden')], 403);
			$want = is_array($post['levels'] ?? null) ? $post['levels'] : [];
			$levels = [];
			foreach (NuAcl::levels() as $l) $levels[(string)$l['zzzzsys_access_id']] = (string)$l['sal_code'];
			$evSent = array_key_exists('everyone', $post);
			$ev = $evSent && is_array($post['everyone']) ? NuFormEveryone::clean($post['everyone']) : null;
			if ($ev !== null) {                                              // Everyone: every level gets the form with these buttons
				if (!NuFormEveryone::allowed($fid)) $out(['ok' => false, 'message' => t('fac_ev_admin')], 403);
				$want = [];
				foreach (array_keys($levels) as $aid) $want[$aid] = ['rights' => $ev['rights'], 'dm' => $ev['dm'], 'ft' => $ev['ft']];
			}
			$before = $rowsOf();
			$plan = [];
			foreach ($want as $aid => $w) {
				$aid = (string)$aid;
				if (!isset($levels[$aid])) continue;
				$old = $before[$aid] ?? null;
				if ($w === null || !is_array($w)) { if ($old) $plan[$aid] = null; continue; }
				$row = [];
				foreach (NuAcl::RIGHTS as $right => $col) $row[$col] = !empty($w['rights'][$right]) ? '1' : '0';
				if ($hasDm) $row['slf_data_mode'] = trim((string)($w['dm'] ?? '')) === '0' ? '0' : null;
				if ($hasFt) $row['slf_form_type'] = in_array(trim((string)($w['ft'] ?? '')), ['0', '1'], true) ? trim((string)$w['ft']) : null;
				$same = $old !== null;
				if ($old) foreach ($row as $c => $v) if ((string)($old[$c] ?? '') !== (string)($v ?? '')) $same = false;
				if (!$same) $plan[$aid] = $row;
			}
			if ($restricted) {
				foreach ($plan as $aid => $row) {
					if (NuUserAccess::holds($aid)) $out(['ok' => false, 'message' => t('secchk_err_own_rights')], 403);
					if (!$adminForm || $row === null) continue;
					foreach (NuSecurity::ADMIN_RIGHTS as $col) {
						if (($row[$col] ?? '0') === '1' && (string)($before[$aid][$col] ?? '') !== '1') $out(['ok' => false, 'message' => t('secchk_err_admin_rights', nu_form_label($fid))], 403);
					}
				}
			}
			$db->transaction(static function (NuDB $db) use ($plan, $before, $fid) {
				foreach ($plan as $aid => $row) {
					$old = $before[$aid] ?? null;
					if ($row === null) $db->run('DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ? AND slf_zzzzsys_form_id = ?', [(string)$aid, $fid]);
					elseif ($old) $db->update('zzzzsys_access_form', $row, 'zzzzsys_access_form_id', (string)$old['zzzzsys_access_form_id']);
					else $db->insert('zzzzsys_access_form', ['zzzzsys_access_form_id' => nu_id(), 'slf_zzzzsys_access_id' => (string)$aid, 'slf_zzzzsys_form_id' => $fid] + $row);
				}
			});
			$evWas = NuFormEveryone::of($fid);
			$evChanged = $evSent && json_encode($evWas) !== json_encode($ev);
			if ($evChanged) {
				NuFormEveryone::set($fid, $ev, (string)(NuAuth::user()['login'] ?? ''));
				NuLog::write('form_everyone', 'zzzzsys_form', $fid, ['form' => (string)$form['sfo_code'], 'on' => $ev !== null]
					+ ($ev !== null ? ['rights' => implode(', ', array_keys(array_filter($ev['rights']))) ?: t('view'), 'new_levels' => $ev['new']] : []));
			}
			foreach ($plan as $aid => $row) {
				$on = $row === null ? [] : array_keys(array_filter(NuAcl::RIGHTS, static fn($c) => ($row[$c] ?? '0') === '1'));
				NuLog::write('access_form_rights', 'zzzzsys_access', (string)$aid, ['code' => $levels[$aid], 'form' => (string)$form['sfo_code']]
					+ ($row === null ? ['removed' => true] : ['rights' => $on ? implode(', ', $on) : t('view')]));
			}
			$out(['ok' => true, 'changed' => count($plan) + ($evChanged ? 1 : 0), 'message' => $plan ? t('fac_saved', count($plan)) : ($evChanged ? t('saved') : t('fac_nothing'))]);
		}
		return compact('canSave', 'out', 'fid', 'rowsOf', 'col', 'hasDm', 'hasFt', 'restricted', 'adminForm', 'form');
	}
}
