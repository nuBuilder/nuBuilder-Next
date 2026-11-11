<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * List of forms: everything the user may open, plus builder links for form designers. Columns, filters and sort come from the chosen
 * list view (0.8.334, core/nulistdefs.php → forms). URL parameters as before: type, group (- = without group), status (all, 1, 0, 3; default: not archived).
 */
$designer = NuAcl::can(NuAcl::FORM_FORM);
$ga = (bool)NuAuth::user()['globeadmin'];
$pagesList = ($_GET['type'] ?? '') === 'page';                        // the list Custom pages (0.8.861): Add opens the custom page dialogue
$buttons = $designer ? '<a class="btn nuAddBtn" id="nuFormsAdd" title="' . h(t($pagesList ? 'nfc_page_new' : 'builder_new_form')) . '" href="' . h(nu_url('builder', $pagesList ? ['new' => '1', 'start' => 'page'] : ['new' => '1'])) . '"><i class="bi bi-plus-lg me-1"></i>' . h(t('add')) . '</a>'
	: '';   // 0.8.848: Add asks how to start (step by step, with AI, copy) – no separate "With AI" button
// tools of the forms area in one menu (only what the user may open)
$tools = array_filter([
	[$designer && !$pagesList, nu_url('forms', ['type' => 'page']), 'bi-filetype-php', t('upd_pages_title')],   // the list Custom pages (0.8.861)
	[isset(nu_forms()['nucodesnippet']) && NuAcl::can('nucodesnippet'), nu_url('form', ['f' => 'nucodesnippet']), 'bi-file-earmark-code', t('nav_snippets')],
	[$ga, nu_url('cloner'), 'bi-files', t('cl_title')],
	[$ga, nu_url('import'), 'bi-file-earmark-arrow-up', t('imp_title')],
	[NuDataExport::can(), nu_url('dataexport'), 'bi-file-earmark-arrow-down', t('dxp_title')],
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
$maint = NuFormStatus::canChange();   // "Maintenance…" (0.8.808: several forms at once) – an entry of Tools since 0.8.1113, was a button of its own
if ($tools || $maint) {
	$toolMenu = '<div class="dropdown"><button class="btn btn-outline-secondary nuToolBtn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-tools me-1"></i>'
		. h(t('forms_tools')) . '</button><ul class="dropdown-menu">';
	foreach ($tools as [, $url, $icon, $label]) $toolMenu .= '<li><a class="dropdown-item" href="' . h($url) . '"><i class="bi ' . $icon . ' me-2"></i>' . h($label) . '</a></li>';
	if ($maint) $toolMenu .= ($tools ? '<li><hr class="dropdown-divider"></li>' : '') . '<li><button type="button" class="dropdown-item" id="nuFmsListBtn" title="' . h(t('fms_list_tip'))
		. '"><i class="bi bi-cone-striped me-2" aria-hidden="true"></i>' . h(t('fms_title')) . '…</button></li>';
	$toolMenu .= '</ul></div>';
}
// status menu of the list (0.8.575): texts and the builder API for assets/js/nuformstatus.js – only for those who may change forms
$stFoot = NuFormDraft::canEdit() && !NuImpersonate::readOnly()
	? '<div id="nuFormStatusData" hidden data-csrf="' . h(nu_csrf_token()) . '" data-url="' . h(nu_url('builderapi')) . '" data-t="' . h((string)json_encode([
		'1' => t('builder_status_1'), '0' => t('builder_status_0'), '2' => t('builder_status_2'), '3' => t('builder_status_3'), 'head' => t('forms_status_change'),
		'done' => t('forms_status_changed'), 'err' => t('forms_status_failed')], JSON_UNESCAPED_UNICODE)) . '"></div>'
		. NuFormStatus::clientHtml() . '<script src="' . h(nu_asset('assets/js/nuformstatus.js')) . '"></script>'   // maintenance (0.8.808): the dialog before the menu
	: '';
// "Access levels of this form" from the column Access levels (0.8.688)
if (NuAcl::can(NuAcl::FORM_ACCESS)) $stFoot .= '<div id="nuFacData" hidden data-csrf="' . h(nu_csrf_token()) . '"></div><script src="' . h(nu_asset('assets/js/nu/nuformaccess.js')) . '"></script>';
NuListView::page('forms', ['buttons' => $buttons, 'buttons_end' => $toolMenu, 'foot' => $stFoot]);
