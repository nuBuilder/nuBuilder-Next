<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuglobalcode.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuGlobalcodePage
{
	/** The POST actions of pages/nuglobalcode.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$gValues = $nuIn['gValues'] ?? null;
		$hashOf = $nuIn['hashOf'] ?? null;
		$cols = $nuIn['cols'] ?? null;
		$c = $nuIn['c'] ?? null;
		$values = $nuIn['values'] ?? null;
		$v = $nuIn['v'] ?? null;
		$row = $nuIn['row'] ?? null;
		$db = $nuIn['db'] ?? null;
		$k = $nuIn['k'] ?? null;
		if (nu_is_post() && nu_post('action') === 'save') {
			NuAdmin::require('globalcode', 'change');
			$set = [];
			$conflict = [];
			$gNew = ['gjs' => null, 'gcss' => null];
			$unwrapped = false;
			foreach ($gValues as $k => $old) {
				if (!isset($_POST['code'][$k]) || !is_string($_POST['code'][$k])) continue;
				$new = str_replace("\r\n", "\n", $_POST['code'][$k]);
				// Global CSS: a surrounding <style>…</style> is removed – it is served as a .css file
				if ($k === 'gcss' && ($plain = NuCustomCode::unwrapStyle($new)) !== null) { $new = $plain; $unwrapped = true; }
				if ($new === $old) continue;
				if ((string)($_POST['hash'][$k] ?? '') !== $hashOf($old)) { $conflict[] = $k; continue; }
				$gNew[$k] = $new;
			}
			$gScope = isset($_POST['scope_sent']) ? array_map(static fn($k) => isset($_POST['scope'][$k]), array_combine(array_keys(NuCustomCode::SCOPES), array_keys(NuCustomCode::SCOPES))) : null;
			foreach ($cols as $k => $c) {
				if (!isset($_POST['code'][$k]) || !is_string($_POST['code'][$k])) continue;
				$new = str_replace("\r\n", "\n", $_POST['code'][$k]);
				if ($new === $values[$k]) continue;
				if ((string)($_POST['hash'][$k] ?? '') !== $hashOf($values[$k])) { $conflict[] = $k; continue; }   // changed by someone else meanwhile
				$set[$c] = $new;
			}
			$gChanged = [];
			if (!$conflict && ($gNew['gjs'] !== null || $gNew['gcss'] !== null || $gScope !== null)) {
				$gChanged = NuCustomCode::save($gNew['gjs'], $gNew['gcss'], $gScope);
				if ($gChanged) NuLog::write('custom_code_saved', 'zzzzsys_nunext_setting', 'custom', ['parts' => $gChanged,
					'chars' => array_filter(['js' => $gNew['gjs'] !== null ? mb_strlen($gNew['gjs']) : null, 'css' => $gNew['gcss'] !== null ? mb_strlen($gNew['gcss']) : null], static fn($v) => $v !== null)]);
				if ($gNew['gjs'] !== null && ($clash = NuCustomCode::hookClashes($gNew['gjs']))) nu_flash('warning', t('scode_hook_clash', implode(', ', $clash)));
			}
			if ($unwrapped && !$conflict) {
				nu_flash('info', t('scode_css_unwrapped'));
			}
			$gNames = array_map(static fn($k) => t('scode_tab_' . ['js' => 'gjs', 'css' => 'gcss', 'scope' => 'scope'][$k]), $gChanged);
			if ($conflict) {
				nu_flash('danger', t('scode_conflict', implode(', ', array_map(static fn($k) => t('scode_tab_' . $k), $conflict))));
				$values = array_merge($values, array_intersect_key(array_map(static fn($v) => str_replace("\r\n", "\n", (string)$v), (array)$_POST['code']), $values));
				$gValues = array_merge($gValues, array_intersect_key(array_map(static fn($v) => str_replace("\r\n", "\n", (string)$v), (array)$_POST['code']), $gValues));
			} elseif (!$set && !$gChanged) {
				nu_flash('info', t('scode_unchanged'));
				$st = preg_replace('/[^a-z]/', '', (string)nu_post('tab'));
				nu_redirect(nu_url('globalcode', $st !== '' ? ['tab' => $st] : []) . ($st !== '' ? '#' . $st : ''));   // ?tab=: the server opens it
			} else {
				$names = [];
				if ($set && $row !== null) {
					$db->update('zzzzsys_setup', $set, 'zzzzsys_setup_id', (string)$row['zzzzsys_setup_id']);
					$names = array_map(static fn($c) => t('scode_tab_' . array_search($c, NU_SETUP_CODE, true)), array_keys($set));
					NuLog::write('setup_code_saved', 'zzzzsys_setup', (string)$row['zzzzsys_setup_id'],
						['fields' => array_keys($set), 'chars' => array_map(static fn($v) => mb_strlen($v), $set)]);
				}
				nu_flash('success', t('scode_saved', implode(', ', array_merge($names, $gNames))));
				$st = preg_replace('/[^a-z]/', '', (string)nu_post('tab'));
				nu_redirect(nu_url('globalcode', $st !== '' ? ['tab' => $st] : []) . ($st !== '' ? '#' . $st : ''));   // ?tab=: the server opens it
			}
		}
		return compact('gValues', 'k', 'hashOf', 'cols', 'values', 'row');
	}
}
