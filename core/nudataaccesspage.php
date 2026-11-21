<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nudataaccess.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuDataaccessPage
{
	/** The POST actions of pages/nudataaccess.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$canSave = $nuIn['canSave'] ?? null;
		$rows = $nuIn['rows'] ?? null;
		$fields = $nuIn['fields'] ?? null;
		$posted = $nuIn['posted'] ?? null;
		$browse = $nuIn['browse'] ?? null;
		$levels = $nuIn['levels'] ?? null;
		$r = $nuIn['r'] ?? null;
		$meta = $nuIn['meta'] ?? null;
		$oid = $nuIn['oid'] ?? null;
		$bid = $nuIn['bid'] ?? null;
		$o = $nuIn['o'] ?? null;
		$b = $nuIn['b'] ?? null;
		$cur = $nuIn['cur'] ?? null;
		if (nu_is_post()) {
			if (!$canSave) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
			$a = nu_post('action');
			try {
				if ($a === 'save_fields') {
					foreach ((array)($_POST['rules'] ?? []) as $oid => $rows) {
						if (!isset($fields[(string)$oid])) continue;
						NuFieldAccess::saveObject($fields[(string)$oid], $posted((array)$rows));
					}
					$tab = 'fields';
				} elseif ($a === 'save_columns') {
					foreach ((array)($_POST['cols'] ?? []) as $bid => $c) {
						if (!isset($browse[(string)$bid]) || !is_array($c)) continue;
						NuBrowseAccess::save((string)$bid, (string)($c['default'] ?? 'show'), $posted((array)($c['rules'] ?? []), true));
					}
					$tab = 'columns';
				} elseif ($a === 'save_level') {
					$lvl = nu_post('level');
					if (!isset($levels[$lvl])) throw new RuntimeException(t('err_invalid'));
					foreach ((array)($_POST['lf'] ?? []) as $oid => $mode) {
						$o = $fields[(string)$oid] ?? null;
						if ($o === null) continue;
						$rules = array_values(array_filter(NuFieldAccess::rulesOf($o), static fn($r) => !($r['type'] === 'access' && $r['subject'] === $lvl)));
						if (in_array((string)$mode, NuFieldAccess::MODES, true)) $rules[] = ['type' => 'access', 'subject' => $lvl, 'mode' => (string)$mode];
						NuFieldAccess::saveObject($o, $rules);
					}
					foreach ((array)($_POST['lc'] ?? []) as $bid => $mode) {
						$b = $browse[(string)$bid] ?? null;
						if ($b === null) continue;
						$cur = NuBrowseAccess::rules($b) ?? ['default' => 'show', 'rules' => []];
						$rules = array_values(array_filter($cur['rules'], static fn($r) => !($r['type'] === 'access' && $r['subject'] === $lvl)));
						if (in_array((string)$mode, ['show', 'hide'], true)) $rules[] = ['type' => 'access', 'subject' => $lvl, 'mode' => (string)$mode];
						NuBrowseAccess::save((string)$bid, $cur['default'], $rules);
					}
					$tab = 'level';
				} else {
					throw new RuntimeException(t('err_invalid'));
				}
				nu_flash('success', t('da_saved'));
			} catch (Throwable $e) {
				nu_flash('danger', t('form_error') . ' ' . $e->getMessage());
				$tab = 'fields';
			}
			nu_redirect(nu_url('dataaccess', ['f' => $meta->id, 'tab' => $tab, 'lvl' => $a === 'save_level' ? nu_post('level') : null]) . '#da_' . $tab);
		}
		return compact('canSave', 'oid', 'fields', 'bid', 'browse', 'levels', 'o', 'r', 'b', 'cur', 'meta');
	}
}
