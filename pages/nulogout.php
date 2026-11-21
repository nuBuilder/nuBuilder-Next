<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

// logout only via POST (CSRF checked in index.php)
NuLogoutPage::post(get_defined_vars());   // the POST actions (code review R7, nulogoutpage.php)
nu_redirect(nu_url('login'));
