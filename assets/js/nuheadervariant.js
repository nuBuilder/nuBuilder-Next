/* nuBuilder Next – TEMPORARY (0.8.634, developer mode, globeadmin): try the header layouts of the mockup in the real pages.
 * User menu → "Header layout (test)" stores the choice in localStorage (nunext-hv) and reloads; this script puts the class
 * nuHv-<a|b|c|d|e> on <html> and rearranges the header (breadcrumb · title · buttons) of record pages, form pages and lists.
 * Buttons that go into the ⋮ menu (variant C, D, E) stay in the page, hidden – the menu entries click them, so form code
 * (nuBuilder Forte's $('#nuDeleteButton') …) keeps working. Remove: this file, the CSS block "header layouts (test)" in
 * nuapp.css, the menu entries in nu_layout_start / the script tag in nu_layout_end (core/nulayout.php) and the texts hlt_*. */
(function () {
	'use strict';
	var KEY = 'nunext-hv', v = '';
	try { v = localStorage.getItem(KEY) || ''; } catch (e) { v = ''; }
	if (!/^[a-e]$/.test(v)) v = '';

	// menu: choose a variant
	document.querySelectorAll('[data-nu-hv]').forEach(function (b) {
		var my = b.getAttribute('data-nu-hv');
		b.classList.toggle('active', my === v || (my === 'now' && v === ''));
		b.addEventListener('click', function () {
			try { if (my === 'now') localStorage.removeItem(KEY); else localStorage.setItem(KEY, my); } catch (e) { /* ignore */ }
			location.reload();
		});
	});
	if (!v || document.body.classList.contains('nu-bare')) return;   // not in iframes (builder preview, lookups)
	var html = document.documentElement;
	html.classList.add('nuHv', 'nuHv-' + v);
	var crumbTitle = v !== 'a', menu = v === 'c' || v === 'd' || v === 'e', sticky = v === 'd';
	var LBL = (document.querySelector('[data-nu-hv-labels]') || {}).dataset || {};

	function isBack(el) { return !!el && (el.id === 'nuBackButton' || el.classList.contains('nuBackBtn') || !!el.querySelector(':scope > .bi-arrow-left')); }

	/** The ⋮ menu: entries that click the (hidden) buttons. */
	function moreMenu(buttons) {
		if (!buttons.length) return null;
		var wrap = document.createElement('div');
		wrap.className = 'dropdown nuHvMore';
		var t = document.createElement('button');
		t.type = 'button'; t.className = 'btn nuBtnAct nuHvMoreBtn'; t.setAttribute('data-bs-toggle', 'dropdown'); t.setAttribute('aria-expanded', 'false');
		t.title = LBL.more || '…'; t.setAttribute('aria-label', LBL.more || '…');
		t.innerHTML = '<i class="bi bi-three-dots-vertical"></i>';
		var ul = document.createElement('ul');
		ul.className = 'dropdown-menu dropdown-menu-end';
		buttons.forEach(function (b) {
			var li = document.createElement('li'), it = document.createElement('button');
			it.type = 'button';
			it.className = 'dropdown-item' + (b.classList.contains('nuBtnDel') || b.classList.contains('btn-outline-danger') ? ' text-danger' : '') + (b.classList.contains('active') ? ' active' : '');
			var icon = b.querySelector('.bi'), text = (b.textContent || '').replace(/\s+/g, ' ').trim() || b.getAttribute('title') || b.getAttribute('aria-label') || '';
			it.innerHTML = (icon ? '<i class="' + icon.className.replace(/\bme-\d\b/g, '') + ' me-2"></i>' : '<i class="bi bi-dot me-2"></i>');
			it.appendChild(document.createTextNode(text));
			it.addEventListener('click', function () { b.click(); });
			li.appendChild(it); ul.appendChild(li);
			b.classList.add('nuHvHidden');
			// a button that form code hides later disappears from the menu too
			new MutationObserver(function () { li.hidden = b.classList.contains('d-none') || b.style.display === 'none' || !b.isConnected; }).observe(b, {attributes: true, attributeFilter: ['class', 'style']});
		});
		wrap.appendChild(t); wrap.appendChild(ul);
		return wrap;
	}

	/** Buttons of a holder that go into the menu: everything but Save / Add / a primary button / own dropdowns. */
	function menuCandidates(holder) {
		var out = [];
		Array.prototype.forEach.call(holder.children, function (el) {
			if (el.classList.contains('btn-group')) {
				Array.prototype.forEach.call(el.querySelectorAll(':scope > a.btn, :scope > button.btn'), function (x) { out.push(x); });
				el.classList.add('nuHvGroupMoved');
				return;
			}
			if (!el.matches('a.btn, button.btn')) return;
			if (el.matches('.nuBtnSave, #nuAddButton, .nuAddBtn, .btn-primary, [data-bs-toggle="dropdown"], .d-none, #nuJsErrors') || isBack(el)) return;
			out.push(el);
		});
		return out;
	}

	function arrange(bar, crumbs, title, holder) {
		bar.classList.add('nuHvBar');
		if (sticky) bar.classList.add('nuHvSticky');
		var back = holder ? Array.prototype.find.call(holder.children, isBack) : null;
		var parentLink = crumbs ? crumbs.querySelector('.breadcrumb-item:not(:last-child) a, .breadcrumb-item:not(.active) a') : null;
		if (crumbTitle && crumbs) {
			var items = crumbs.querySelectorAll('.breadcrumb-item');
			var last = items[items.length - 1];
			if (title && last) {                                     // the title (with its icon / menu) becomes the last breadcrumb item
				last.textContent = '';
				while (title.firstChild) last.appendChild(title.firstChild);
				title.classList.add('nuHvHidden');
			}
			if (last) last.classList.add('nuHvCrumbTitle');
			var lastLink = items.length > 1 ? items[items.length - 2].querySelector('a') : null;
			if (lastLink && !lastLink.querySelector('.nuHvArrow')) {
				var ar = document.createElement('i'); ar.className = 'bi bi-arrow-left me-1 nuHvArrow'; lastLink.prepend(ar);
			}
			if (back && (lastLink || parentLink)) back.classList.add('nuHvHidden');
		} else if (back && title) {                                   // A: Back = an arrow in front of the title
			back.classList.add('nuHvBackIcon');
			title.prepend(back);
		}
		if (menu && holder) {
			var m = moreMenu(menuCandidates(holder));
			// a ⋮ menu of its own already (form pages: options menu) – the entries go on top of it
			var own = Array.prototype.find.call(holder.querySelectorAll('.dropdown'), function (d) { return d !== m && d.querySelector(':scope > .btn .bi-three-dots-vertical, :scope > button .bi-three-dots-vertical'); });
			var ownUl = own ? own.querySelector('.dropdown-menu') : null;
			if (m && ownUl) {
				var items = Array.prototype.slice.call(m.querySelectorAll('.dropdown-menu > li'));
				var hr = document.createElement('li'); hr.innerHTML = '<hr class="dropdown-divider">';
				ownUl.prepend(hr);
				items.reverse().forEach(function (li) { ownUl.prepend(li); });
			} else if (m) holder.appendChild(m);
			var save = holder.querySelector('.nuBtnSave');
			if (save) save.classList.add('nuHvSave');
		}
	}

	function run() {
		// form pages: .nuFormHeader = breadcrumb + #nuActionHolder
		document.querySelectorAll('.nuFormHeader').forEach(function (bar) {
			if (bar.classList.contains('nuHvBar')) return;
			arrange(bar, bar.querySelector('.breadcrumb'), null, bar.querySelector('#nuActionHolder'));
		});
		// own record pages: <nav breadcrumb> + .nuTitleBar (h1 + buttons)
		document.querySelectorAll('main nav[aria-label="breadcrumb"] + .nuTitleBar, main nav[aria-label="breadcrumb"] + div > .nuTitleBar').forEach(function (tb) {
			if (tb.classList.contains('nuHvBar') || tb.closest('.nuFormHeader')) return;
			var nav = tb.previousElementSibling && tb.previousElementSibling.matches('nav') ? tb.previousElementSibling : tb.parentElement.previousElementSibling;
			var h1 = tb.querySelector(':scope > h1'), holder = tb.querySelector(':scope > div:last-child:not(.nuTitleSub)');
			if (crumbTitle && nav) { tb.prepend(nav); nav.classList.add('nuHvNav'); }
			arrange(tb, nav ? nav.querySelector('.breadcrumb') : null, h1, holder);
		});
		// lists and other pages: .nuTitleBar without breadcrumb – title and buttons in one row
		document.querySelectorAll('main .nuTitleBar:not(.nuHvBar)').forEach(function (tb) {
			tb.classList.add('nuHvBar', 'nuHvPlain');
			if (sticky && !tb.closest('.card')) tb.classList.add('nuHvSticky');
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
	window.addEventListener('load', run);        // form pages: buttons added by nuOnLoad
})();
