<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * List of forms: everything the user may open, plus builder links for form designers. Columns, filters and sort come from the chosen
 * list view (0.8.334, core/nulistdefs.php → forms). URL parameters as before: type, group (- = without group), status (all, 1, 0, 3; default: not archived).
 */
$designer = NuAcl::can(NuAcl::FORM_FORM);
$ga = (bool)NuAuth::user()['globeadmin'];
$buttons = $designer ? '<a class="btn nuAddBtn" title="' . h(t('builder_new_form')) . '" href="' . h(nu_url('builder', ['new' => '1'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>' : '';
// tools of the forms area in one menu (only what the user may open)
$tools = array_filter([
	[isset(nu_forms()['nucodesnippet']) && NuAcl::can('nucodesnippet'), nu_url('form', ['f' => 'nucodesnippet']), 'bi-file-earmark-code', t('nav_snippets')],
	[$ga, nu_url('cloner'), 'bi-files', t('cl_title')],
	[$ga, nu_url('import'), 'bi-filetype-csv', t('imp_title')],
	[NuAcl::can(NuAcl::FORM_PROMPT), nu_url('prompts'), 'bi-stars', t('pg_title')],
	[NuAcl::can(NuAcl::FORM_PROCEDURE), nu_url('procedures'), 'bi-filetype-php', t('proc_title')],
	[NuAcl::can(NuAcl::FORM_REPORT), nu_url('reports'), 'bi-file-earmark-richtext', t('rpt_title_list')],
	[NuAcl::can(NuAcl::FORM_SELECT), nu_url('sqlbuilder'), 'bi-diagram-3', t('sqb_title')],
	[NuAcl::can(NuAcl::FORM_ITEM), nu_url('items'), 'bi-list-ul', t('item_title')],
	[NuAcl::can(NuAcl::FORM_FILE), nu_url('files'), 'bi-images', t('file_title')],
	[NuAcl::can(NuAcl::FORM_TRANSLATE), nu_url('translations'), 'bi-translate', t('trl_title')],
	[NuAcl::can(NuAcl::FORM_FORMAT), nu_url('formats'), 'bi-type', t('fmt_title')],
], static fn($x) => $x[0]);
$toolMenu = '';
if ($tools) {
	$toolMenu = '<div class="dropdown"><button class="btn btn-outline-secondary nuToolBtn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-tools me-1"></i>'
		. h(t('forms_tools')) . '</button><ul class="dropdown-menu">';
	foreach ($tools as [, $url, $icon, $label]) $toolMenu .= '<li><a class="dropdown-item" href="' . h($url) . '"><i class="bi ' . $icon . ' me-2"></i>' . h($label) . '</a></li>';
	$toolMenu .= '</ul></div>';
}
NuListView::page('forms', ['buttons' => $buttons, 'buttons_end' => $toolMenu]);
