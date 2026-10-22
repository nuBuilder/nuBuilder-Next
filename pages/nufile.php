<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * A file of zzzzsys_file for forms (nuAttachImage, nuAttachButtonImage …): any logged-in user, like nuBuilder's "getfile".
 *   ?p=file&code=<sfi_code>  or  &id=<zzzzsys_file_id>   (&dl=1 = download)
 */

$db = nu_db();
if (!$db->tableExists(NuFile::TABLE)) { http_response_code(404); exit; }
$code = (string)($_GET['code'] ?? '');
$row = $code !== ''
	? $db->one('SELECT zzzzsys_file_id, sfi_json FROM zzzzsys_file WHERE LOWER(sfi_code) = LOWER(?)', [$code])
	: $db->one('SELECT zzzzsys_file_id, sfi_json FROM zzzzsys_file WHERE zzzzsys_file_id = ?', [(string)($_GET['id'] ?? '')]);
NuFile::serve($row, !empty($_GET['dl']));
