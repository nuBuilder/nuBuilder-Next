<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Login and change log (zzzzsys_nunext_log). Globeadmin, or access levels with rights on "nunextlog". Columns, filters and sort come
 * from the chosen list view (0.8.334, core/nulistdefs.php → log). URL parameters as before: event, login, q, from, to.
 */
NuAcl::require(NuAcl::FORM_LOG);
NuSchema::ensure();
$tabs = NuAudit::tabs('log');                // debug results · log · audit trail · sessions
NuListView::page('log', ['before' => $tabs, 'active' => 'log']);
