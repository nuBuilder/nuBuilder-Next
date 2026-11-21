<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * The bell (assets/js/nunotify.js – core/nunotify.php):
 *   GET                                  → {unread, items (latest 10, not hidden), unread_ids, confirm (still to confirm)} – does not keep
 *                                          an idle session alive (NU_PASSIVE, index.php)
 *   POST {action: read|unread|hide|unhide|confirm, id} / {action: readall} (X-CSRF-Token) → the same summary
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!nu_is_post()) session_write_close();                     // the check every minute holds no session lock


NuNotifyApiPage::post(get_defined_vars());   // the POST actions (code review R7, nunotifyapipage.php)
NuNotifyApiPage::out(['ok' => true] + NuNotify::summary());
