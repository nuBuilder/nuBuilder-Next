<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Formats API (POST JSON, X-CSRF-Token) for the formats page and the form builder.
 * Rights: nuBuilder's form "nuformat"; reading is also allowed for form designers ("nuform").
 */

header('Content-Type: application/json; charset=utf-8');

function nf_out(array $d, int $status = 200): never
{
	http_response_code($status);
	echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

if (!nu_is_post()) nf_out(['ok' => false, 'error' => 'POST required'], 405);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) nf_out(['ok' => false, 'error' => 'Invalid request'], 400);

$action  = (string)($in['action'] ?? '');
$canRead = NuAcl::can(NuAcl::FORM_FORMAT) || NuAcl::can(NuAcl::FORM_FORM);
if (!$canRead) nf_out(['ok' => false, 'error' => t('err_forbidden')], 403);
if (NuDemo::active() && !in_array($action, ['list', 'get'], true)) nf_out(['ok' => false, 'error' => t('demo_not_available')], 403);   // demo mode: read only

switch ($action) {
	case 'list':
		$letters = isset($in['letters']) ? preg_replace('/[^DNT]/', '', strtoupper((string)$in['letters'])) : null;
		nf_out(['ok' => true, 'formats' => NuFormat::choices($letters ?: null), 'rights' => nu_format_rights()]);

	case 'get':
		$r = null;
		foreach (NuFormat::registry(true) as $x) if ($x['id'] === (string)($in['id'] ?? '')) $r = $x;
		if (!$r) nf_out(['ok' => false, 'error' => t('err_not_found')], 404);
		nf_out(['ok' => true, 'format' => $r, 'usage' => NuFormat::usage($r['key'])]);

	case 'save':
		$id = trim((string)($in['id'] ?? ''));
		if (!NuAcl::can(NuAcl::FORM_FORMAT, $id === '' ? 'create' : 'save')) nf_out(['ok' => false, 'error' => t('err_forbidden')], 403);
		$before = null;
		if ($id !== '') foreach (NuFormat::registry(true) as $x) if ($x['id'] === $id) $before = $x;
		[$newId, $errors] = NuFormat::save($in);
		if ($errors) nf_out(['ok' => false, 'errors' => $errors, 'error' => reset($errors)]);
		$after = null;
		foreach (NuFormat::registry(true) as $x) if ($x['id'] === $newId) $after = $x;
		NuLog::write($id === '' ? 'format_created' : 'format_changed', 'zzzzsys_format', (string)$newId,
			$before ? ['fields' => NuLog::diff($before, (array)$after)] : ['format' => $after['key'] ?? '']);
		nf_out(['ok' => true, 'format' => $after, 'formats' => NuFormat::choices(), 'client' => NuFormat::clientRegistry()]);

	case 'delete':
		if (!NuAcl::can(NuAcl::FORM_FORMAT, 'delete')) nf_out(['ok' => false, 'error' => t('err_forbidden')], 403);
		$id = (string)($in['id'] ?? '');
		$key = '';
		foreach (NuFormat::registry(true) as $x) if ($x['id'] === $id) $key = $x['key'];
		if (str_starts_with($id, 'nu')) nf_out(['ok' => false, 'error' => t('fmt_err_system')]);
		if (($err = NuFormat::delete($id)) !== null) nf_out(['ok' => false, 'error' => $err]);
		NuLog::write('format_deleted', 'zzzzsys_format', $id, ['format' => $key]);
		nf_out(['ok' => true, 'formats' => NuFormat::choices()]);
}
nf_out(['ok' => false, 'error' => 'Unknown action'], 400);
