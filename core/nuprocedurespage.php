<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuprocedures.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuProceduresPage
{
	/** The POST actions of pages/nuprocedures.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$proc = $nuIn['proc'] ?? null; $T = $nuIn['T'] ?? null; $id = $nuIn['id'] ?? null; $src = $nuIn['src'] ?? null; $errors = $nuIn['errors'] ?? null; $db = $nuIn['db'] ?? null; $f = $nuIn['f'] ?? null;
		if (nu_is_post() && nu_post('action') === 'run') {
			header('Content-Type: application/json; charset=utf-8');
			if (!$proc) { echo json_encode(['ok' => false, 'errors' => [['message' => t('record_not_found')]]]); exit; }
			$props = json_decode((string)($_POST['props'] ?? '{}'), true);
			$res = NuProcedure::run($proc, is_array($props) ? $props : []);
			NuLog::write('procedure_run', $T, $id, ['code' => (string)$proc['sph_code'], 'ok' => $res['ok']]);
			echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			exit;
		}

		// ------------------------------------------------------------------ save / clone / delete
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save') {
				NuAcl::require(NuAcl::FORM_PROCEDURE, $proc ? 'save' : ($src ? 'clone' : 'create'));
				if ($proc && NuProcedure::locked($proc)) NuAcl::require('__globeadmin_only__');
				$in = [];
				foreach (NuProcedure::FIELDS as $f) $in[$f] = $_POST[$f] ?? '';
				foreach (['sph_javascript_active', 'sph_global', 'sph_demo'] as $f) $in[$f] = nu_check($f) === '1';
				[$newId, $errors] = NuProcedure::save($in, $proc ? $id : '');
				if (!$errors) {
					$after = $db->one("SELECT * FROM $T WHERE zzzzsys_php_id = ?", [$newId]) ?? [];
					$acc = isset($_POST['access_sent']) ? NuProcedure::setAccess($newId, array_filter((array)($_POST['access'] ?? []), 'is_string')) : ['added' => [], 'removed' => []];
					$details = ['code' => (string)$after['sph_code'], 'fields' => NuProcedure::diff($proc, $after)];
					if ($acc['added']) $details['access_added'] = implode(', ', $acc['added']);
					if ($acc['removed']) $details['access_removed'] = implode(', ', $acc['removed']);
					if ($src) $details['clone_of'] = (string)($src['sph_code'] ?? '');
					if ($details['fields'] || count($details) > 2 || !$proc) NuLog::write($proc ? 'procedure_changed' : 'procedure_created', $T, $newId, $details);
					nu_flash('success', t('saved'));
					nu_redirect(nu_url('procedures', ['id' => $newId]));
				}
			}
			if ($action === 'delete' && $proc) {
				NuAcl::require(NuAcl::FORM_PROCEDURE, 'delete');
				if (NuProcedure::locked($proc)) NuAcl::require('__globeadmin_only__');
				$objects = array_filter(NuProcedure::usage($id, (string)$proc['sph_code']), static fn($u) => $u['kind'] !== 'code');
				if ($objects) {
					$errors['_'] = t('proc_err_used', implode(', ', array_map(static fn($u) => trim($u['form'] . ' · ' . $u['where'], ' ·'), $objects)));
				} else {
					$db->transaction(static function (NuDB $db) use ($id, $T) {
						$db->delete('zzzzsys_access_php', 'slp_zzzzsys_php_id', $id);
						$db->delete($T, 'zzzzsys_php_id', $id);
					});
					NuLog::write('procedure_deleted', $T, $id, ['code' => (string)$proc['sph_code'], 'fields' => ['sph_php' => [(string)$proc['sph_php'], null]]]);
					nu_flash('success', t('proc_deleted'));
					nu_redirect(nu_url('procedures'));
				}
			}
		}
		return compact('proc', 'T', 'id', 'src', 'f', 'errors', 'db');
	}
}
