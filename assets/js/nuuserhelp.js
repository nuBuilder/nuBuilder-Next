/* User help pages (0.8.1023, core/nuuserhelp.php): the side panel of the form pages (? button #nuHelpButton, F1, the (?) marks of
   fields .nuUhTip) and the reader page (?p=userhelp: print). The panel loads ?p=userhelp&c=<code>&frag=1 – HTML cleaned on the server.
   window.nuUserHelpOpen(code) opens a page in the panel; window.nuUserHelpF1() is asked by nuapp.js's F1. */
(function () {
	'use strict';
	var print = document.getElementById('nuUhPrint');
	if (print) print.addEventListener('click', function () { window.print(); });
	var fold = document.querySelector('.nuUhNavFold');                 // phones: the contents folded, the page first
	if (fold && window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches && document.getElementById('nuUhArticle')) fold.open = false;

	var btn = document.getElementById('nuHelpButton');
	var T = {};
	var tEl = document.getElementById('nuUhPanelTexts');
	var base = (btn && btn.getAttribute('data-nu-uhelp-base')) || (tEl && tEl.getAttribute('data-uhelp-base')) || 'index.php?p=userhelp';
	if (tEl) ['close', 'back', 'newtab', 'loading', 'error', 'insection', 'edit'].forEach(function (k) { T[k] = tEl.getAttribute('data-' + k) || k; });
	var panel = null, bodyEl, titleEl, newTab, backBtn, editLink, history = [], current = '', opener = null;

	var tabs = {};
	try { tabs = JSON.parse((btn && btn.getAttribute('data-nu-uhelp-tabs')) || '{}') || {}; } catch (e) { tabs = {}; }
	function currentCode() {
		if (!btn) return '';
		var act = document.querySelector('#nuTabHolder .nuTab.active[data-nu-tab-id], .nuTab.active[data-nu-tab-id]');
		var id = act ? act.getAttribute('data-nu-tab-id') : '';
		return (id && tabs[id]) || btn.getAttribute('data-nu-uhelp') || '';
	}
	function syncButton() { if (btn) btn.classList.toggle('d-none', !currentCode()); }

	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
	function build() {
		if (panel) return;
		panel = el('aside', 'nuUhPanel');
		panel.id = 'nuUhPanel';
		panel.setAttribute('role', 'complementary');
		panel.setAttribute('aria-labelledby', 'nuUhPanelTitle');
		panel.hidden = true;
		var head = el('div', 'nuUhPanelHead');
		backBtn = el('button', 'btn btn-sm btn-outline-secondary');
		backBtn.type = 'button'; backBtn.id = 'nuUhPanelBack'; backBtn.title = T.back; backBtn.setAttribute('aria-label', T.back);
		backBtn.innerHTML = '<i class="bi bi-arrow-left" aria-hidden="true"></i>';
		titleEl = el('h2', 'h6 mb-0 nuUhPanelTitle'); titleEl.id = 'nuUhPanelTitle';
		editLink = el('a', 'btn btn-sm btn-outline-secondary d-none');
		editLink.id = 'nuUhPanelEdit'; editLink.target = '_blank'; editLink.rel = 'noopener'; editLink.title = T.edit; editLink.setAttribute('aria-label', T.edit);
		editLink.innerHTML = '<i class="bi bi-pencil" aria-hidden="true"></i>';
		newTab = el('a', 'btn btn-sm btn-outline-secondary');
		newTab.id = 'nuUhPanelTab'; newTab.target = '_blank'; newTab.rel = 'noopener'; newTab.title = T.newtab; newTab.setAttribute('aria-label', T.newtab);
		newTab.innerHTML = '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>';
		var close = el('button', 'btn-close');
		close.type = 'button'; close.id = 'nuUhPanelClose'; close.setAttribute('aria-label', T.close);
		head.append(backBtn, titleEl, editLink, newTab, close);
		bodyEl = el('div', 'nuUhPanelBody nuUhArticle');
		bodyEl.id = 'nuUhPanelBody';
		bodyEl.tabIndex = 0;
		panel.append(head, bodyEl);
		document.body.appendChild(panel);
		close.addEventListener('click', hide);
		backBtn.addEventListener('click', function () { if (history.length) load(history.pop(), false); });
		bodyEl.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('a[data-nu-uhelp]');
			if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
			e.preventDefault();
			load(a.getAttribute('data-nu-uhelp'), true);
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !panel.hidden && !document.querySelector('.modal.show')) { e.stopPropagation(); hide(); }
		});
	}
	function hide() {
		if (!panel || panel.hidden) return;
		panel.hidden = true;
		document.documentElement.classList.remove('nuUhPanelOpen');
		if (opener && opener.focus) opener.focus();
	}
	function load(code, push) {
		if (!code) return;
		build();
		if (push && current && current !== code) history.push(current);
		current = code;
		backBtn.classList.toggle('d-none', !history.length);
		bodyEl.textContent = T.loading;
		bodyEl.setAttribute('aria-busy', 'true');
		var sep = base.indexOf('?') >= 0 ? '&' : '?';
		fetch(base + sep + 'c=' + encodeURIComponent(code) + '&frag=1', {credentials: 'same-origin', headers: {Accept: 'application/json'}})
			.then(function (r) { return r.json(); }).then(function (j) {
				bodyEl.removeAttribute('aria-busy');
				if (!j || !j.ok) { titleEl.textContent = ''; bodyEl.textContent = (j && j.error) || T.error; return; }
				titleEl.textContent = j.title;
				newTab.href = j.url;
				editLink.classList.toggle('d-none', !j.edit);
				if (j.edit) editLink.href = j.edit;
				bodyEl.innerHTML = j.html;      // cleaned on the server (NuHtmlClean::doc)
				if (j.children && j.children.length) {
					var nav = el('nav', 'nuUhChildren mt-3'), h = el('h3', 'h6 text-uppercase text-body-secondary', T.insection), ul = el('ul');
					j.children.forEach(function (c) {
						var li = el('li'), a = el('a', '', c.title);
						a.href = 'index.php?p=userhelp&c=' + encodeURIComponent(c.code);
						a.setAttribute('data-nu-uhelp', c.code);
						li.appendChild(a); ul.appendChild(li);
					});
					nav.append(h, ul); bodyEl.appendChild(nav);
				}
				bodyEl.scrollTop = 0;
			}).catch(function () { bodyEl.removeAttribute('aria-busy'); bodyEl.textContent = T.error; });
	}
	function open(code) {
		if (!code) return;
		build();
		opener = document.activeElement;
		history = [];
		current = '';
		panel.hidden = false;
		document.documentElement.classList.add('nuUhPanelOpen');
		load(code, false);
		var c = document.getElementById('nuUhPanelClose');
		if (c) c.focus();
	}
	window.nuUserHelpOpen = open;
	window.nuUserHelpF1 = function () {
		var c = currentCode();
		if (!c) return false;
		if (panel && !panel.hidden && current) { hide(); return true; }
		open(c);
		return true;
	};
	if (btn) {
		btn.addEventListener('click', function () { if (panel && !panel.hidden) hide(); else open(currentCode()); });
		document.addEventListener('shown.bs.tab', syncButton);
		syncButton();
	}
	document.addEventListener('click', function (e) {
		var tip = e.target.closest && e.target.closest('.nuUhTip[data-nu-uhelp]');
		if (!tip) return;
		e.preventDefault(); e.stopPropagation();
		open(tip.getAttribute('data-nu-uhelp'));
	}, true);
	document.addEventListener('keydown', function (e) {
		var tip = e.target.closest && e.target.closest('.nuUhTip[data-nu-uhelp]');
		if (!tip || (e.key !== 'Enter' && e.key !== ' ')) return;
		e.preventDefault(); e.stopPropagation();
		open(tip.getAttribute('data-nu-uhelp'));
	}, true);
})();
