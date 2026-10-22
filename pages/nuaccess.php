<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Access levels list – columns, phone cards, filters and sort come from the chosen list view (0.8.329): catalogue in
 * core/nulistdefs.php, rendering and views in core/nulistview.php.
 */
NuAcl::require(NuAcl::FORM_ACCESS);
$rights = NuAcl::rights(NuAcl::FORM_ACCESS);
$buttons = $rights['add'] ? '<a class="btn nuAddBtn" title="' . h(t('access_new')) . '" href="' . h(nu_url('accesslevel')) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '';
NuListView::page('access', ['buttons' => $buttons]);
