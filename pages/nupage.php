<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Form type "page" (0.8.387): the page file's own output – index.php?p=page&f=<form code>[&r=…][&action=…].
 * Login and the view right on the form are checked before any page code runs (core/nupage.php).
 */
NuPage::run();
