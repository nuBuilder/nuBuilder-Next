/*
 * Side bar (0.8.460, core/nusidebar.php / nu_sidebar() in core/nulayout.php): favourites and recently used on the left.
 * From 992 px a column next to the page: « collapses it to an icon rail (remembered per device, localStorage nunext-sidebar-collapsed;
 * nutheme.js sets html.nuSbCollapsed before drawing). Narrower: a drawer opened by the button in the top bar (Esc / backdrop close).
 * Icon colours come as data-nu-color (strict CSP: no style attributes in the markup).
 */
(function () {
	'use strict';
	var sb = document.getElementById('nuSidebar');
	if (!sb) return;
	var root = document.documentElement;
	var wide = window.matchMedia('(min-width: 992px)');

	sb.querySelectorAll('[data-nu-color]').forEach(function (el) { el.style.setProperty('--nu-sb-c', el.getAttribute('data-nu-color')); });

	// the top bar's height: the side bar sticks below it
	var bar = document.querySelector('.nu-navbar');
	function barHeight() { if (bar) root.style.setProperty('--nu-nav-h', bar.offsetHeight + 'px'); }
	barHeight();
	window.addEventListener('resize', barHeight);

	// ---------------------------------------------------------------- collapse (wide screens)
	var col = document.getElementById('nuSbCollapse');
	function syncCollapse() {
		var c = root.classList.contains('nuSbCollapsed');
		var t = sb.getAttribute(c ? 'data-t-expand' : 'data-t-collapse') || '';
		col.setAttribute('aria-expanded', c ? 'false' : 'true');
		col.setAttribute('title', t);
		col.setAttribute('aria-label', t);
	}
	col.addEventListener('click', function () {
		var c = !root.classList.contains('nuSbCollapsed');
		root.classList.toggle('nuSbCollapsed', c);
		try { localStorage.setItem('nunext-sidebar-collapsed', c ? '1' : '0'); } catch (e) {}
		syncCollapse();
	});
	syncCollapse();

	// ---------------------------------------------------------------- drawer (narrow screens)
	var openBtn = document.getElementById('nuSbOpen'), closeBtn = document.getElementById('nuSbClose'), backdrop = null, before = null;
	function close() {
		if (!document.body.classList.contains('nuSbOpen')) return;
		document.body.classList.remove('nuSbOpen');
		if (backdrop) { backdrop.remove(); backdrop = null; }
		if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
		if (before && before.focus) before.focus();
	}
	function open() {
		before = document.activeElement;
		document.body.classList.add('nuSbOpen');
		backdrop = document.createElement('div');
		backdrop.className = 'nuSbBackdrop';
		backdrop.addEventListener('click', close);
		document.body.appendChild(backdrop);
		if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
		var first = sb.querySelector('.nuSbLink, .nuSbClose');
		if (first) first.focus();
	}
	if (openBtn) openBtn.addEventListener('click', function () { if (document.body.classList.contains('nuSbOpen')) close(); else open(); });
	if (closeBtn) closeBtn.addEventListener('click', close);
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
	(wide.addEventListener ? wide.addEventListener.bind(wide, 'change') : wide.addListener.bind(wide))(function () { if (wide.matches) close(); });

	// ---------------------------------------------------------------- right-click menu of "Recently used" (0.8.489)
	// One entry "Clear recently used" – submits the section's own clear form (POST ?p=sidebar action clear). Only while the list has entries.
	var recent = sb.querySelector('.nuSbSec-recent'), menu = null, at = 0;
	function closeMenu() { if (menu) { menu.remove(); menu = null; } }
	function onScroll() { if (menu && Math.abs(recent.getBoundingClientRect().top - at) > 2) closeMenu(); }   // only when the list really moved
	if (recent) {
		recent.addEventListener('contextmenu', function (e) {
			var form = recent.querySelector('.nuSbClearForm');
			if (!form) return;                                   // nothing to clear: the browser's own menu
			e.preventDefault();
			closeMenu();
			var label = recent.getAttribute('data-t-clear') || (form.querySelector('button') || {}).title || '';
			menu = document.createElement('ul');
			menu.className = 'dropdown-menu show nuSbMenu';
			menu.setAttribute('role', 'menu');
			var li = document.createElement('li'), btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'dropdown-item';
			btn.setAttribute('role', 'menuitem');
			var ic = document.createElement('i');
			ic.className = 'bi bi-x-circle me-2';
			ic.setAttribute('aria-hidden', 'true');
			btn.appendChild(ic);
			btn.appendChild(document.createTextNode(label));
			btn.addEventListener('click', function () {
				closeMenu();
				if (form.requestSubmit) form.requestSubmit(); else form.submit();
			});
			li.appendChild(btn);
			menu.appendChild(li);
			document.body.appendChild(menu);
			var x = e.clientX, y = e.clientY;
			if (!x && !y) {                                      // context-menu key / Shift+F10: below the focused element
				var r = (document.activeElement && recent.contains(document.activeElement) ? document.activeElement : recent).getBoundingClientRect();
				x = r.left + 8; y = r.top + Math.min(r.height, 32);
			}
			var w = menu.offsetWidth, h = menu.offsetHeight;
			menu.style.left = Math.max(4, Math.min(x, window.innerWidth - w - 4)) + 'px';
			menu.style.top = Math.max(4, Math.min(y, window.innerHeight - h - 4)) + 'px';
			at = recent.getBoundingClientRect().top;
			btn.focus({preventScroll: true});
		});
		document.addEventListener('pointerdown', function (e) { if (menu && !menu.contains(e.target)) closeMenu(); }, true);
		document.addEventListener('keydown', function (e) { if (menu && e.key === 'Escape') { e.stopPropagation(); closeMenu(); } }, true);
		window.addEventListener('scroll', onScroll, true);
		window.addEventListener('resize', closeMenu);
		window.addEventListener('blur', closeMenu);
	}
})();
