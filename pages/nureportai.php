<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Reports with AI (0.8.1097, core/nureportspec.php): ?p=reportai&id=<report id> – the POST actions of the dialogue on the report
 * page and in the report designer (assets/js/nureportai.js):
 *   prompt   request, allow_sql, tables[]     → {ok, text}                      the prompt to copy
 *   context  samples                          → a Markdown file                 to attach to the chat
 *   check    answer, allow_sql                → {ok, errors, warnings, plan}    nothing is written
 *   preview  answer, allow_sql (form post)    → the PDF with the described layout (criteria empty), nothing is written
 *   apply    answer, allow_sql, note          → {ok, url}                       the old layout becomes a version, the new one is stored
 *   restore  version (form post)              → back to the report page         a version is put back
 * A GET goes to the report page.
 */

NuAcl::require(NuAcl::FORM_REPORT);
$id = (string)($_GET['id'] ?? '');
$report = NuReport::load($id);
if (!$report || (string)$report['zzzzsys_report_id'] !== $id) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
if (!nu_is_post()) nu_redirect(nu_url('reports', ['id' => $id]));
$canSave = NuAcl::can(NuAcl::FORM_REPORT, 'save');
$action = nu_post('action');
$allowSql = nu_post('allow_sql') === '1' && NuReportSpec::canSql();
$details = static function (Throwable $e): string {                   // SQL / paths only for the globeadmin or with debug
	$u = NuAuth::user();
	return ($u['globeadmin'] && empty($u['demo'])) || nu_debug() ? ' ' . $e->getMessage() : '';
};

// ------------------------------------------------------------------ restore a version (form post from the History tab)
if ($action === 'restore') {
	if (!$canSave) { http_response_code(403); nu_fatal(t('err_forbidden'), '', true); }
	$ok = NuReportSpec::restore($report, nu_post('version'));
	nu_flash($ok ? 'success' : 'danger', t($ok ? 'rai_restored' : 'record_not_found'));
	nu_redirect(nu_post('back') === 'designer' ? nu_url('reportdesigner', ['id' => $id]) : nu_url('reports', ['id' => $id]) . '#rptTabHistory');
}

// ------------------------------------------------------------------ preview: the PDF of the answer (form post into a new tab)
if ($action === 'preview') {
	try {
		$spec = NuReportSpec::parse((string)($_POST['answer'] ?? ''));
		if (is_string($spec)) throw new RuntimeException($spec);
		$r = NuReportSpec::check($spec, $report, null, $allowSql);
		if ($r['errors']) throw new RuntimeException(implode(' · ', $r['errors']));
		$layout = NuReport::layoutJson(NuReportSpec::toLayout($r['spec'], NuReport::layout((string)$report['sre_layout'])));
		$run = $report;
		if (isset($r['spec']['source'])) {                             // the AI's query, not saved: run read-only
			NuReport::$sqlText[NuReportSpec::TEMP_SQL] = $r['spec']['source']['sql'];
			$run['sre_zzzzsys_php_id'] = 'SQL:' . NuReportSpec::TEMP_SQL;
		}
		$hash = NuHash::plain();
		$launch = trim((string)$report['sre_zzzzsys_form_id']);
		if ($launch !== '') foreach (nu_db()->all('SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$launch]) as $o) $hash[(string)$o['sob_all_id']] ??= '';
		session_write_close();
		$pdf = NuReport::pdf($run, $hash, $layout);
	} catch (Throwable $e) {
		error_log('nuBuilder Next report AI preview ' . $report['sre_code'] . ': ' . $e->getMessage());
		NuReport::fail($e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : t('rpt_err_run', (string)$report['sre_code']) . $details($e));
	}
	NuReport::send($pdf, (string)$report['sre_code']);
}

// ------------------------------------------------------------------ the context file
if ($action === 'context') {
	$name = 'nunext-report-context-' . preg_replace('/[^\w-]+/', '_', (string)$report['sre_code']) . '-' . NU_VERSION . '.md';
	$body = NuReportSpec::contextFile($report, nu_post('samples') === '1');
	header('Content-Type: text/markdown; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	header('X-Nu-File-Name: ' . $name);
	header('Cache-Control: no-store');
	NuLog::write('report_ai_context', NuReport::TABLE, $id, ['code' => (string)$report['sre_code'], 'samples' => nu_post('samples') === '1']);
	echo $body;
	exit;
}

// ------------------------------------------------------------------ JSON actions
header('Content-Type: application/json; charset=utf-8');
$out = static function (array $d): never { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
try {
	if ($action === 'prompt') {
		$req = trim(nu_post('request'));
		if ($req === '') $out(['ok' => false, 'error' => t('rai_err_request')]);
		$tables = array_values(array_filter(array_map('strval', (array)($_POST['tables'] ?? [])), static fn($t) => $t !== ''));
		$out(['ok' => true, 'text' => NuReportSpec::prompt($report, $req, ['allow_sql' => $allowSql, 'tables' => $tables])]);
	}
	if ($action === 'check' || $action === 'apply') {
		$spec = NuReportSpec::parse((string)($_POST['answer'] ?? ''));
		if (is_string($spec)) $out(['ok' => false, 'errors' => [$spec], 'warnings' => []]);
		if ($action === 'check') {
			$r = NuReportSpec::check($spec, $report, null, $allowSql);
			$out(['ok' => !$r['errors'], 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'plan' => $r['plan']]);
		}
		if (!$canSave) $out(['ok' => false, 'errors' => [t('err_forbidden')]]);
		NuReportSpec::apply($report, $spec, $allowSql, nu_post('note'));
		nu_flash('success', t('rai_applied'));
		$out(['ok' => true, 'url' => nu_post('back') === 'designer' ? nu_url('reportdesigner', ['id' => $id]) : nu_url('reports', ['id' => $id])]);
	}
} catch (Throwable $e) {
	error_log('nuBuilder Next report AI ' . $report['sre_code'] . ': ' . $e->getMessage());
	$out(['ok' => false, 'errors' => $e instanceof RuntimeException && !($e instanceof PDOException) ? explode("\n", $e->getMessage()) : [t('err_unexpected') . $details($e)], 'warnings' => []]);
}
$out(['ok' => false, 'errors' => ['?']]);
