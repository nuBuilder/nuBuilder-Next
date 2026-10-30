<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* POST ?p=jqwarn – jQuery Migrate messages of a form page (0.8.741, assets/js/nu/nujqmigrate.js → NuJquery::addWarnings). Only while the
   user's page runs Migrate with warnings; never in demo mode. Answers 204. */

header('Cache-Control: no-store');
if (!nu_is_post() || NuDemo::active() || NuJquery::current()['migrate'] !== 'warn') { http_response_code(204); exit; }
$msgs = json_decode((string)($_POST['msgs'] ?? ''), true);
$form = (string)($_POST['form'] ?? '');
if (is_array($msgs) && preg_match('/^[\w.-]{1,64}$/', $form)) {
	try { NuJquery::addWarnings($form, array_values(array_filter($msgs, 'is_string')), mb_substr((string)($_POST['jq'] ?? ''), 0, 20)); } catch (Throwable) { /* best effort */ }
}
http_response_code(204);
exit;
