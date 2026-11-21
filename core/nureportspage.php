<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nureports.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuReportsPage
{
	/** The POST actions of pages/nureports.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$report = $nuIn['report'] ?? null;
		$src = $nuIn['src'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$id = $nuIn['id'] ?? null;
		$db = $nuIn['db'] ?? null;
		$T = $nuIn['T'] ?? null;
		$cloneId = $nuIn['cloneId'] ?? null;
		$f = $nuIn['f'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save') {
				NuAcl::require(NuAcl::FORM_REPORT, $report ? 'save' : ($src ? 'clone' : 'create'));
				$in = [];
				foreach (['sre_code', 'sre_description', 'sre_group', 'sre_zzzzsys_form_id', 'sre_zzzzsys_php_id'] as $f) $in[$f] = $_POST[$f] ?? '';
				[$newId, $errors] = NuReport::save($in, $report ? $id : '');
				if (!$errors) {
					// a clone takes the layout of its source as stored (as nuBuilder Forte copies the field)
					if (!$report && $src) $db->update($T, ['sre_layout' => $src['sre_layout']], 'zzzzsys_report_id', (string)$newId);
					$after = $db->one("SELECT * FROM $T WHERE zzzzsys_report_id = ?", [$newId]) ?? [];
					$acc = isset($_POST['access_sent']) ? NuReport::setAccess($newId, array_filter((array)($_POST['access'] ?? []), 'is_string')) : ['added' => [], 'removed' => []];
					$details = ['code' => (string)$after['sre_code'], 'fields' => NuReport::diff($report, $after)];
					if ($acc['added']) $details['access_added'] = implode(', ', $acc['added']);
					if ($acc['removed']) $details['access_removed'] = implode(', ', $acc['removed']);
					if ($src) $details['clone_of'] = (string)$db->value("SELECT sre_code FROM $T WHERE zzzzsys_report_id = ?", [$cloneId]);
					if ($details['fields'] || count($details) > 2 || !$report) NuLog::write($report ? 'report_changed' : 'report_created', $T, $newId, $details);
					nu_flash('success', t('saved'));
					nu_redirect(nu_post('then') === 'design' ? nu_url('reportdesigner', ['id' => $newId]) : nu_url('reports', ['id' => $newId, 'ai' => nu_post('then') === 'ai' ? '1' : null]));
				}
			}
			if ($action === 'delete' && $report) {
				NuAcl::require(NuAcl::FORM_REPORT, 'delete');
				$objects = array_filter(NuReport::usage($id, (string)$report['sre_code']), static fn($u) => $u['kind'] === 'object');
				if ($objects) {
					$errors['_'] = t('rpt_err_used', implode(', ', array_map(static fn($u) => trim($u['form'] . ' · ' . $u['where'], ' ·'), $objects)));
				} else {
					$db->transaction(static function (NuDB $db) use ($id, $T) {
						$db->delete(NuReport::ACCESS, 'sre_zzzzsys_report_id', $id);
						$db->delete($T, 'zzzzsys_report_id', $id);
					});
					NuReportSpec::deleteVersions($id);
					NuLog::write('report_deleted', $T, $id, ['code' => (string)$report['sre_code']]);
					nu_flash('success', t('rpt_deleted'));
					nu_redirect(nu_url('reports'));
				}
			}
		}
		return compact('report', 'src', 'f', 'errors', 'id', 'db', 'T', 'cloneId');
	}
}
