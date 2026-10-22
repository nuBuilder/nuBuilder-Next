<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Opens a report for the user (nuBuilder's "getreport"): ?c=<code or id>
 * With a launch form (sre_zzzzsys_form_id) the form opens first – its fields are the criteria – with a "Run report" button;
 * without one the PDF is made right away. &iframe=1 keeps it inside an iframe run object.
 */
$code   = (string)($_GET['c'] ?? '');
$report = NuReport::load($code);
if (!$report) NuReport::fail(t('rpt_err_missing', $code), 404);
if (!NuReport::canRun($report)) NuReport::fail(t('rpt_err_denied', (string)$report['sre_code']), 403);

$launch = trim((string)$report['sre_zzzzsys_form_id']);
if ($launch !== '' && NuFormMeta::load($launch) && NuAcl::can($launch)) {
	nu_redirect(nu_url('form', array_filter(['f' => $launch, 'r' => '-1', 'rpt' => (string)$report['sre_code'], 'iframe' => ($_GET['iframe'] ?? '') === '1' ? '1' : null,
		'bt' => $_GET['bt'] ?? null], static fn($v) => $v !== null && $v !== '')));
}
nu_redirect(nu_url('reportpdf', ['c' => (string)$report['sre_code']]));
