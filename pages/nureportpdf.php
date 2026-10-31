<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Runs a report and sends the PDF (nuBuilder's core/nurunpdf.php):
 *   GET  ?i=<token>                   a run prepared by the form API (nuRunReport: report + the form's hash variables)
 *   GET  ?c=<code or id>[&dl=1]       a run without a form (reports list, run objects outside a form) – hash: user and date variables
 *   POST action=save, i=<token>, tag, file_name   nuRunReportSave: writes the PDF to the temp folder → JSON {filename, id}
 *   POST action=email, i=<token>, to, subject, body, file_name   nuEmailReportPDF: sends the PDF as attachment → JSON {ok, message}
 */

$token = (string)($_REQUEST['i'] ?? '');
$code  = (string)($_GET['c'] ?? '');
$json  = nu_is_post();

$fail = static function (string $msg, int $status = 400) use ($json): never {
	if ($json) {
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['ok' => false, 'errors' => [['message' => $msg, 'field' => '']], 'filename' => null, 'id' => null], JSON_UNESCAPED_UNICODE);
		exit;
	}
	NuReport::fail($msg, $status);
};

if (!NuReport::available()) $fail(t('rpt_err_tables'), 404);
if ($token !== '') {
	$run = NuReport::fromToken($token);
	if (!$run) $fail(t('rpt_err_expired'), 404);
	$report = $run['report'];
	$hash = $run['hash'];
} else {
	$report = NuReport::load($code);
	if (!$report) $fail(t('rpt_err_missing', $code), 404);
	$hash = NuHash::plain();
}
if (!NuReport::canRun($report)) $fail(t('rpt_err_denied', (string)$report['sre_code']), 403);

try {
	$pdf = NuReport::pdf($report, $hash);
} catch (Throwable $e) {
	error_log('nuBuilder Next report ' . $report['sre_code'] . ': ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
	$u = NuAuth::user();
	$fail(t('rpt_err_run', (string)$report['sre_code']) . ($u['globeadmin'] || nu_debug() ? ' ' . $e->getMessage() : ''), 500);
}
NuLog::write('report_run', NuReport::TABLE, (string)$report['zzzzsys_report_id'], ['code' => (string)$report['sre_code'], 'bytes' => strlen($pdf)]);

if ($json) {
	header('Content-Type: application/json; charset=utf-8');
	$action = nu_post('action');
	if ($action === 'save') {
		try {
			echo json_encode(NuReport::saveFile($pdf, (string)$report['sre_code'], nu_post('tag'), nu_post('file_name')) + ['ok' => true], JSON_UNESCAPED_UNICODE);
		} catch (Throwable $e) {
			error_log('nuBuilder Next report save: ' . $e->getMessage());
			$fail(NuAuth::user()['globeadmin'] || nu_debug() ? $e->getMessage() : t('err_unexpected'), 500);
		}
		exit;
	}
	if ($action === 'email') {
		$name = trim(nu_post('file_name')) !== '' ? basename(nu_post('file_name')) : (string)$report['sre_code'] . '.pdf';
		if (!str_ends_with(strtolower($name), '.pdf')) $name .= '.pdf';
		require_once NU_ROOT . '/core/form/nuphp_api.php';
		$res = nuSendEmail(['to' => nu_post('to'), 'cc' => nu_post('cc'), 'bcc' => nu_post('bcc'), 'subject' => nu_post('subject') !== '' ? nu_post('subject') : (string)$report['sre_description'],
			'body' => nu_post('body'), 'html' => true, 'attachments' => [$name => ['data' => $pdf, 'type' => 'application/pdf']]]);
		$ok = !empty($res[0]);
		NuLog::write('report_emailed', NuReport::TABLE, (string)$report['zzzzsys_report_id'], ['code' => (string)$report['sre_code'], 'to' => nu_post('to'), 'ok' => $ok]);
		echo json_encode(['ok' => $ok, 'message' => (string)($res[1] ?? ''), 'info' => (string)($res[2] ?? '')], JSON_UNESCAPED_UNICODE);
		exit;
	}
	$fail('Unknown action');
}
NuReport::send($pdf, (string)($report['sre_code'] ?: 'report'), ($_GET['dl'] ?? '') === '1');
