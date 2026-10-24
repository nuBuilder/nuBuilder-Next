<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * File upload of nuBuilder's Uppy objects (0.8.459) – nuBuilder's core/nuapi.php + core/nuupload.php:
 * Uppy posts the file (field "file") and its meta data procedure, record_id (+ form_id, added by nuUppyCreate()). nuBuilder's
 * session_id is not used: here the login cookie and the CSRF header (X-CSRF-Token, added by nuUppyCreate(), which also points the
 * template's endpoint 'core/nuapi.php' to this route) count. The procedure – default nu_upload_file_template – runs with the user's
 * procedure rights and the form's hash variables; its answer is its variable $result (JSON text) with the HTTP status it set
 * (http_response_code(201)), exactly as in nuBuilder. Errors: nuSetUploadError() → 401 + {error, message}.
 * Before any procedure runs, file names a web server might execute are refused (NuSecurity::dangerousUploadName).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
require_once NU_ROOT . '/core/form/nuphp_api.php';            // nuSetUploadError, nuTranslate …
NuTranslate::activate();

/** Ends with nuBuilder's error answer (401, {error, message}); the message is translated like nuBuilder's (zzzzsys_translate). */
function nu_upload_fail(string $message): never
{
	echo nuSetUploadError(nuTranslate($message));
	exit;
}

if (!nu_is_post()) { http_response_code(405); exit(json_encode(['error' => 'POST required', 'message' => 'POST required'])); }

http_response_code(400);                                      // as nuBuilder: the procedure sets 201 when the file is stored
$f = $_FILES['file'] ?? null;
if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) nu_upload_fail('Invalid file data');
switch ((int)$f['error']) {
	case UPLOAD_ERR_OK:
		break;
	case UPLOAD_ERR_NO_FILE:
		nu_upload_fail('No file uploaded');
	case UPLOAD_ERR_INI_SIZE:
	case UPLOAD_ERR_FORM_SIZE:
		nu_upload_fail('Exceeded file size limit');
	default:
		nu_upload_fail('Unknown file error');
}
if (!is_uploaded_file((string)$f['tmp_name'])) nu_upload_fail('Invalid file data');

$name   = (string)$f['name'];
$proc   = trim(nu_post('procedure')) !== '' ? trim(nu_post('procedure')) : 'nu_upload_file_template';
$formId = trim(nu_post('form_id'));
$record = trim(nu_post('record_id'));
$logDetails = ['file' => mb_substr($name, 0, 200), 'size' => (int)$f['size'], 'procedure' => $proc, 'form' => $formId, 'record' => $record];

// never a file the web server could run or obey – also for nuBuilder's own template, which checks only the content type
if (NuSecurity::dangerousUploadName($name)) {
	NuLog::write('file_upload_refused', 'zzzzsys_php', $proc, $logDetails);
	nu_upload_fail('Invalid file type');
}

// hash variables of the form the object is on (#RECORD_ID#, #FORM_ID#, the user's …) – the form must be open for the user
$meta = $formId !== '' ? NuFormMeta::load($formId) : null;
if ($formId !== '' && (!$meta || !NuAcl::can($meta->id))) nu_upload_fail('Access denied');
$hash = $meta ? NuHash::forForm($meta, $record !== '' ? $record : '-1') : NuHash::base() + ['RECORD_ID' => $record];

NuPhp::reset();
$r = NuPhp::procedure($proc, $hash);
NuPhp::$output = '';                                          // echo of the procedure would spoil the JSON answer
if (empty($r['ok'])) {
	NuLog::write('file_upload_refused', 'zzzzsys_php', $proc, $logDetails + ['error' => (string)($r['error'] ?? '')]);
	nu_upload_fail((string)($r['error'] ?? 'Unknown Procedure Name'));
}
$status = http_response_code();
if (NuPhp::$errors) {                                          // the procedure failed (exception, syntax error)
	NuLog::write('file_upload_refused', 'zzzzsys_php', $proc, $logDetails + ['error' => (string)NuPhp::$errors[0]['message']]);
	if (NuPhp::$result === null || $status < 400) nu_upload_fail((string)NuPhp::$errors[0]['message']);
}
NuLog::write($status >= 200 && $status < 300 ? 'file_upload' : 'file_upload_refused', 'zzzzsys_php', $proc, $logDetails + ['status' => $status]);
$res = NuPhp::$result;
echo is_string($res) ? $res : json_encode($res ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
