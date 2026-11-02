<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Maintenance of forms (0.8.808, core/nuformstatus.php) – JSON for the forms list (status menu, "Maintenance…" dialog) and design mode.
 *   GET  ?p=formstatus[&f=<id> | &all=1]  → {forms: [{id, code, description, status, text, until}] (forms in maintenance, or the one form), can_change}
 *   POST {forms: [ids], on: true|false, text, until} (X-CSRF-Token) → {ok, changed: [ids]}
 * Who may switch: those who may change a form's status (NuFormDraft::canEdit), not while acting as a user (read only), not in demo mode.
 */
$out = static function (array $d, int $code = 200): never {
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	exit((string)json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
};
if (!NuAcl::can(NuAcl::FORM_FORM)) $out(['ok' => false, 'message' => t('err_forbidden')], 403);

if (!nu_is_post()) {
	$fid = trim((string)($_GET['f'] ?? ''));
	$rows = ($_GET['all'] ?? '') === '1'               // the dialog's list: every own form
		? nu_db()->all("SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' AND COALESCE(sfo_type, '') <> 'subform' ORDER BY sfo_code")
		: ($fid !== ''
		? nu_db()->all('SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$fid])
		: nu_db()->all("SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_status FROM zzzzsys_form WHERE sfo_status = '2' ORDER BY sfo_code"));
	$forms = [];
	foreach ($rows as $r) {
		$i = NuFormStatus::info((string)$r['zzzzsys_form_id']);
		$forms[] = ['id' => (string)$r['zzzzsys_form_id'], 'code' => (string)$r['sfo_code'], 'description' => (string)$r['sfo_description'],
			'status' => NuFormStatus::norm($r['sfo_status']), 'text' => $i['text'] ?? '', 'until' => $i['until'] ?? ''];
	}
	$out(['ok' => true, 'forms' => $forms, 'can_change' => NuFormStatus::canChange()]);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $out(['ok' => false, 'message' => 'Invalid request'], 400);
if (!NuFormStatus::canChange()) $out(['ok' => false, 'message' => t('err_forbidden')], 403);
$ids = array_values(array_filter(array_map('strval', is_array($in['forms'] ?? null) ? $in['forms'] : []), static fn($x) => $x !== ''));
if (!$ids) $out(['ok' => false, 'message' => t('fms_none_chosen')], 422);
$until = (string)($in['until'] ?? '');
if ($until !== '' && NuFormStatus::cleanUntil($until) === '') $out(['ok' => false, 'message' => t('fms_until_bad')], 422);
try {
	$done = NuFormStatus::setMaintenance(array_slice($ids, 0, 500), !empty($in['on']), mb_substr((string)($in['text'] ?? ''), 0, NuFormStatus::TEXT_LEN), $until);
} catch (RuntimeException $e) {
	$out(['ok' => false, 'message' => $e->getMessage()], 403);
}
$out(['ok' => true, 'changed' => $done]);
