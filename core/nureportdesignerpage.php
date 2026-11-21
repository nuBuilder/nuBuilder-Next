<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nureportdesigner.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuReportdesignerPage
{
	/** The POST actions of pages/nureportdesigner.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$report = $nuIn['report'] ?? null; $db = $nuIn['db'] ?? null; $canSave = $nuIn['canSave'] ?? null; $id = $nuIn['id'] ?? null;
		if (nu_is_post() && nu_post('action') === 'preview') {
			try {
				$layout = NuReport::layoutJson((string)($_POST['layout'] ?? ''));
				$hash = NuHash::plain();
				$launch = trim((string)$report['sre_zzzzsys_form_id']);
				if ($launch !== '') foreach ($db->all('SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$launch]) as $o) $hash[(string)$o['sob_all_id']] ??= '';
				$crit = json_decode((string)($_POST['criteria'] ?? '{}'), true);
				if (is_array($crit)) foreach ($crit as $k => $v) if (is_string($k) && preg_match('/^\w{1,100}$/', $k) && is_scalar($v)) $hash[$k] = (string)$v;
				if (nu_post('fmt') === 'xlsx') {                     // Excel of this layout
					$xlsx = NuReportXlsx::build($report, $hash, $layout);
					NuReportXlsx::send($xlsx, (string)($report['sre_code'] ?: 'report'));
				}
				$pdf = NuReport::pdf($report, $hash, $layout);
			} catch (Throwable $e) {
				error_log('nuBuilder Next report preview ' . $report['sre_code'] . ': ' . $e->getMessage());
				$u = NuAuth::user();                                 // details (SQL, file paths) only for the globeadmin or with $nuConfigDebug
				NuReport::fail(t('rpt_err_run', (string)$report['sre_code']) . (($u['globeadmin'] && empty($u['demo'])) || nu_debug() ? ' ' . $e->getMessage() : ''));
			}
			NuReport::send($pdf, (string)$report['sre_code']);
		}

		// ------------------------------------------------------------------ background actions (form data: action, layout)
		if (nu_is_post() && in_array(nu_post('action'), ['save', 'fields'], true)) {
			$json = ['action' => nu_post('action'), 'layout' => (string)($_POST['layout'] ?? '')];
			header('Content-Type: application/json; charset=utf-8');
			$out = static function (array $d, int $status = 200): never { http_response_code($status); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
			try {
				switch ((string)($json['action'] ?? '')) {
					case 'save':
						if (!$canSave) $out(['ok' => false, 'message' => t('err_forbidden')], 403);
						$layout = NuReport::keepTypes(NuReport::layoutJson($json['layout'] ?? null), (string)$report['sre_layout']);
						if ($layout !== (string)$report['sre_layout']) {
							$db->update(NuReport::TABLE, ['sre_layout' => $layout], 'zzzzsys_report_id', $id);
							NuLog::write('report_changed', NuReport::TABLE, $id, ['code' => (string)$report['sre_code'], 'fields' => ['sre_layout' => ['…', '…']]]);
						}
						$out(['ok' => true, 'layout' => json_decode($layout)]);
					case 'fields':
						$out(['ok' => true] + NuReport::fields((string)$report['sre_zzzzsys_php_id'], trim((string)$report['sre_zzzzsys_form_id'])));
				}
				$out(['ok' => false, 'message' => 'Unknown action'], 400);
			} catch (Throwable $e) {
				error_log('nuBuilder Next report designer ' . $report['sre_code'] . ': ' . $e->getMessage());
				$u = NuAuth::user();
				$out(['ok' => false, 'message' => ($u['globeadmin'] && empty($u['demo'])) || nu_debug() ? $e->getMessage() : t('err_unexpected')], 200);
			}
		}
		return compact('report', 'canSave', 'id');
	}
}
