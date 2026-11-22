<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * View mode (core/nurecordview.php): "Always open in view mode" of one form for the signed-in user.
 * POST ?p=viewfirst {action: set, key: form id | 'user', on: 1 | 0} → {ok, on}. CSRF checked by index.php; demo visitors keep it in the session.
 */
if (!nu_is_post() || nu_post('action') !== 'set') nu_json(['ok' => false, 'error' => 'bad_request'], 400);
$key = nu_post('key');
$on = nu_post('on') === '1';
if (!NuRecordView::setFirst($key, $on)) nu_json(['ok' => false, 'error' => 'bad_key'], 400);
nu_json(['ok' => true, 'on' => NuRecordView::first($key)]);
