<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

// Page numbers (0.8.804, core/nupageno.php): ?p=pageno&n=P100 → the page; unknown number → back with a message
$n = (string)($_GET['n'] ?? '');
$url = NuPageNo::url($n);
if ($url === '') {
	nu_flash('warning', t('pno_unknown', mb_substr(trim($n), 0, 20)));
	$back = NuAuth::validNext((string)($_GET['back'] ?? ''));          // the page the number was typed on (index.php?…)
	nu_redirect($back ?? nu_url('home'));
}
nu_redirect($url);
