<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Users list – columns, phone cards, filters and sort come from the chosen list view (0.8.329): catalogue in core/nulistdefs.php,
 * rendering and views in core/nulistview.php. The URL parameters of the filters (access, pos, state …) are the same as before.
 */
NuAcl::require(NuAcl::FORM_USER);
$rights = NuAcl::rights(NuAcl::FORM_USER);
$buttons = '';
$more = [];
if ($rights['add']) {
	$buttons = '<a class="btn nuAddBtn" title="' . h(t('user_new')) . '" href="' . h(nu_url('user')) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>';
	// Import (and Print): buttons on larger screens, a ⋮ menu right of Add on phones (0.8.371)
	$more[] = ['icon' => 'bi-upload', 'label' => t('imp_u_button'), 'title' => t('imp_u_button_tip'), 'href' => nu_url('import', ['target' => 'users']), 'blank' => true, 'id' => 'nuUsersImport'];
}
NuListView::page('users', ['buttons' => $buttons, 'more' => $more]);
