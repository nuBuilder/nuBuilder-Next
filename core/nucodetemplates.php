<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Templates of Admin tools → Custom code (0.8.524): ready-made snippets per tab, inserted at the cursor (▾ Templates) or picked in a
 * preview dialogue (assets/js/nuglobalcode.js). Built-in only. Title / description = texts ctpl_<id> / ctpl_<id>_d; the code itself is
 * code (English comments). Examples use nu:pageload listeners where several may be combined – only the skeleton defines nuOnPageLoad.
 */
final class NuCodeTemplates
{
	/** category => text key */
	public const CATS = ['look' => 'ctpl_cat_look', 'page' => 'ctpl_cat_page', 'form' => 'ctpl_cat_form', 'fn' => 'ctpl_cat_fn'];

	/** @return array<string, list<array{id: string, cat: string, code: string}>> tab => templates */
	public static function all(): array
	{
		return [
			// ------------------------------------------------------------------ Form pages: Setup → Style (CSS)
			'style' => [
				['id' => 's_btn_round', 'cat' => 'look', 'code' => <<<'CSS'
/* Form buttons (input + run): fully rounded, in an accent colour of their own (the default is the Branding accent) */
.btn.nuFormBtn:not([class*="btn-"]) {
	--bs-btn-border-radius: 2rem;
	--bs-btn-bg: #2f6fd6; --bs-btn-hover-bg: #2f6fd6; --bs-btn-active-bg: #2f6fd6; --bs-btn-disabled-bg: #2f6fd6;
}
CSS],
				['id' => 's_btn_light', 'cat' => 'look', 'code' => <<<'CSS'
/* Form buttons (input + run): light surface, thin border, dark text – instead of the solid accent colour */
.btn.nuFormBtn:not([class*="btn-"]) {
	--nu-btn-ink: #334155; --bs-btn-bg: #f8fafc; --bs-btn-border-color: #e2e8f0; --bs-btn-border-radius: .55rem;
	--bs-btn-hover-bg: #eef2f6; --bs-btn-hover-border-color: #cbd5e1; --bs-btn-active-bg: #e2e8f0; --bs-btn-active-border-color: #cbd5e1;
	--bs-btn-disabled-bg: #f8fafc; --bs-btn-disabled-border-color: #e2e8f0;
	box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
}
.btn.nuFormBtn:not([class*="btn-"]):hover, .btn.nuFormBtn:not([class*="btn-"]):active { filter: none; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
[data-bs-theme="dark"] .btn.nuFormBtn:not([class*="btn-"]) {
	--nu-btn-ink: #e2e8f0; --bs-btn-bg: #1e293b; --bs-btn-border-color: #334155; --bs-btn-hover-bg: #273449; --bs-btn-hover-border-color: #475569;
	--bs-btn-active-bg: #334155; --bs-btn-active-border-color: #475569; --bs-btn-disabled-bg: #1e293b; --bs-btn-disabled-border-color: #334155; box-shadow: none;
}
CSS],
				['id' => 's_btn_save', 'cat' => 'look', 'code' => <<<'CSS'
/* Save button: green while nothing is changed (it turns red when there are unsaved changes) */
.btn.nuBtnSave:not(.nuSaveEdited) { background: #e8f5ee; border-color: #b7dfc7; color: #146c43; }
.btn.nuBtnSave:not(.nuSaveEdited):hover { background: #d4ecdf; }
CSS],
				['id' => 's_labels', 'cat' => 'look', 'code' => <<<'CSS'
/* Field labels: darker and semi-bold */
.nuLabelLeft, .nuLabelTop { color: var(--bs-body-color); font-weight: 600; }
CSS],
				['id' => 's_compact', 'cat' => 'look', 'code' => <<<'CSS'
/* Compact fields: smaller text in inputs, selects and lists */
.nuEditForm .form-control, .nuEditForm .form-select { font-size: .82rem; }
.nuBrowseTable { font-size: .85rem; }
CSS],
				['id' => 's_striped', 'cat' => 'look', 'code' => <<<'CSS'
/* Browse lists: every second row slightly shaded */
.nuBrowseTable > tbody > tr:nth-child(even) > * { --bs-table-bg-type: rgba(0, 0, 0, .025); }
[data-bs-theme="dark"] .nuBrowseTable > tbody > tr:nth-child(even) > * { --bs-table-bg-type: rgba(255, 255, 255, .03); }
CSS],
				['id' => 's_tabs', 'cat' => 'look', 'code' => <<<'CSS'
/* Form tabs: pill style */
.nav-tabs .nav-link.nuTab { border-radius: 2rem; margin-right: .25rem; }
.nav-tabs .nav-link.nuTab.active { background: #2f6fd6; color: #fff; }
CSS],
			],
			// ------------------------------------------------------------------ Form pages: Setup → Header (JavaScript)
			'header' => [
				['id' => 'h_hooks', 'cat' => 'form', 'code' => <<<'JS'
// nuBuilder's global hooks: run on every form page (a form's own nuOnLoad runs after them)
function nuLoadBrowseGlobal(formId, formCode) {
	// every browse list
}

function nuLoadEditGlobal(formId, formCode) {
	// every edit form
}
JS],
				['id' => 'h_confirm_save', 'cat' => 'form', 'code' => <<<'JS'
// Ask before every save – return false cancels
function nuBeforeSaveGlobal() {
	return confirm('Save the changes?');
}
JS],
				['id' => 'h_upper', 'cat' => 'form', 'code' => <<<'JS'
// Upper case while typing: call nuUpperCase('field_id') in a form's nuOnLoad
function nuUpperCase(id) {
	$('#' + id).on('input', function () {
		const p = this.selectionStart;
		this.value = this.value.toUpperCase();
		this.setSelectionRange(p, p);
	});
}
JS],
				['id' => 'h_color_rows', 'cat' => 'fn', 'code' => <<<'JS'
// Colour browse rows by the text of one column: colorRows(2, {'Open': '#fff3cd', 'Closed': '#d1e7dd'})
// (column numbers start at 0 and include hidden columns; matching ignores upper / lower case)
function colorRows(column, colors) {
	const map = {};
	Object.keys(colors).forEach(function (k) { map[k.toLowerCase()] = colors[k]; });
	nuBrowseLoop([column], function (cell) {
		const c = map[$(cell).text().trim().toLowerCase()];
		if (c) cell.closest('tr').style.setProperty('--bs-table-bg-type', c);
	});
}
JS],
				['id' => 'h_infobar', 'cat' => 'fn', 'code' => <<<'JS'
// Short messages: note('Saved'), note('Check the date', 'warning') – info, success, warning, error
function note(text, type) {
	$.nuInfoBar[type || 'success'](text, {autoCloseDelay: 3000});
}
JS],
			],
			// ------------------------------------------------------------------ Form pages: Setup → Include (HTML)
			'include' => [
				['id' => 'i_font', 'cat' => 'look', 'code' => <<<'HTML'
<!-- A web font for all form pages -->
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap">
<style>
	.nuForm { font-family: 'Inter', system-ui, sans-serif; }
</style>
HTML],
				['id' => 'i_css', 'cat' => 'fn', 'code' => <<<'HTML'
<!-- A style sheet from another server -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css">
HTML],
				['id' => 'i_js', 'cat' => 'fn', 'code' => <<<'HTML'
<!-- A JavaScript library from another server (use a fixed version) -->
<script src="https://cdn.jsdelivr.net/npm/dayjs@1.11.13/dayjs.min.js"></script>
HTML],
			],
			// ------------------------------------------------------------------ All pages: Global JS
			'gjs' => [
				['id' => 'g_skeleton', 'cat' => 'page', 'code' => <<<'JS'
// Page hook: runs on every page once it is ready (form pages: after nuOnLoad). Check page first.
// page: 'home', 'form', 'users', 'login' … · info: {page, mode, form, admin}
function nuOnPageLoad(page, info) {
	if (page === 'home') {
		// start page
	}
	if (page === 'form' && info.mode === 'edit') {
		// every edit form – info.form is the form code
	}
}
JS],
				['id' => 'g_banner', 'cat' => 'page', 'code' => <<<'JS'
// A notice at the top of the start page
document.addEventListener('nu:pageload', function (e) {
	if (e.detail.page !== 'home') return;
	const note = document.createElement('div');
	note.className = 'alert alert-info d-flex align-items-center gap-2';
	note.innerHTML = '<i class="bi bi-info-circle"></i><span></span>';
	note.querySelector('span').textContent = 'Maintenance on Friday from 18:00 – please save your work.';
	document.getElementById('main').prepend(note);
});
JS],
				['id' => 'g_hide', 'cat' => 'page', 'code' => <<<'JS'
// Hide an element on one page for everybody except admins (change page and selector)
document.addEventListener('nu:pageload', function (e) {
	if (e.detail.page !== 'users' || e.detail.admin) return;
	document.querySelectorAll('.your-selector').forEach(function (el) { el.hidden = true; });
});
JS],
				['id' => 'g_color_rows', 'cat' => 'form', 'code' => <<<'JS'
// Colour the rows of one form's browse list by the text of a column (form code, column number from 0)
document.addEventListener('nu:pageload', function (e) {
	const d = e.detail;
	if (d.page !== 'form' || d.form !== 'customer' || d.mode !== 'browse') return;
	const colors = {open: '#fff3cd', closed: '#d1e7dd', overdue: '#f8d7da'};
	nuBrowseLoop([2], function (cell) {
		const c = colors[cell.textContent.trim().toLowerCase()];
		if (c) cell.closest('tr').style.setProperty('--bs-table-bg-type', c);
	});
});
JS],
				['id' => 'g_extlinks', 'cat' => 'page', 'code' => <<<'JS'
// Links to other websites open in a new tab (on every page)
document.addEventListener('nu:pageload', function () {
	document.querySelectorAll('a[href^="http"]').forEach(function (a) {
		if (a.host !== location.host && !a.target) { a.target = '_blank'; a.rel = 'noopener'; }
	});
});
JS],
				['id' => 'g_toast', 'cat' => 'fn', 'code' => <<<'JS'
// A small message at the top right on any page: nuToastNote('Done'), nuToastNote('Failed', 'danger')
function nuToastNote(text, type) {
	let box = document.getElementById('myToasts');
	if (!box) {
		box = document.createElement('div');
		box.id = 'myToasts';
		box.className = 'toast-container position-fixed top-0 end-0 p-3';
		document.body.appendChild(box);
	}
	const t = document.createElement('div');
	t.className = 'toast align-items-center border-0 text-bg-' + (type || 'success');
	t.setAttribute('role', 'status');
	t.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
	t.querySelector('.toast-body').textContent = text;
	box.appendChild(t);
	t.addEventListener('hidden.bs.toast', function () { t.remove(); });
	nuBs.toast(t, {delay: 3000}).show();
}
JS],
				['id' => 'g_debounce', 'cat' => 'fn', 'code' => <<<'JS'
// Run a function only after the user stopped typing: el.addEventListener('input', debounce(search, 300))
function debounce(fn, ms) {
	let timer = 0;
	return function () {
		const args = arguments, self = this;
		clearTimeout(timer);
		timer = setTimeout(function () { fn.apply(self, args); }, ms);
	};
}
JS],
			],
			// ------------------------------------------------------------------ All pages: Global CSS
			'gcss' => [
				['id' => 'c_btn', 'cat' => 'look', 'code' => <<<'CSS'
/* Toolbar buttons (Back, Save, Clone, Delete …) on every page: rounded */
.btn.nuBtnAct { border-radius: 2rem; }
CSS],
				['id' => 'c_font', 'cat' => 'look', 'code' => <<<'CSS'
/* Slightly larger text everywhere */
body.nu-app { font-size: 1.05rem; }
CSS],
				['id' => 'c_navbar', 'cat' => 'look', 'code' => <<<'CSS'
/* Coloured top bar */
.nu-navbar { background: #12355b !important; }
.nu-navbar .btn, .nu-navbar .nav-link { color: #fff; border-color: rgba(255, 255, 255, .35); }
CSS],
				['id' => 'c_sidebar', 'cat' => 'look', 'code' => <<<'CSS'
/* Side bar in the brand colour */
.nuSidebar { background: #12355b; color: #fff; }
.nuSidebar .nuSbLink, .nuSidebar .nuSbSecTitle, .nuSidebar .nuSbTitle { color: #fff; }
CSS],
				['id' => 'c_tiles', 'cat' => 'page', 'code' => <<<'CSS'
/* Start page: taller tiles with a stronger shadow */
body[data-nu-page="home"] .nuHomeTile { min-height: 140px; box-shadow: 0 4px 14px rgba(15, 23, 42, .08); }
CSS],
				['id' => 'c_login', 'cat' => 'page', 'code' => <<<'CSS'
/* Sign-in page: coloured background (switch on "Sign-in pages" above) */
body[data-nu-page="login"] { background: linear-gradient(135deg, #12355b, #2f6fd6); }
CSS],
				['id' => 'c_form', 'cat' => 'page', 'code' => <<<'CSS'
/* One form only: wider labels and a light background in edit mode (change the form code) */
body[data-nu-form="customer"][data-nu-mode="edit"] .nuEditForm { background: #f8fafc; }
CSS],
			],
		];
	}

	/** Data for the page script: tab => [{id, cat, title, desc, code}], plus the texts of the menu. */
	public static function forPage(): array
	{
		$out = [];
		foreach (self::all() as $tab => $list) {
			foreach ($list as $tpl) $out[$tab][] = $tpl + ['title' => t('ctpl_' . $tpl['id']), 'desc' => t('ctpl_' . $tpl['id'] . '_d')];
		}
		$cats = [];
		foreach (self::CATS as $k => $key) $cats[$k] = t($key);
		return ['tabs' => $out, 'cats' => $cats];
	}
}
