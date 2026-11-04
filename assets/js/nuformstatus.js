/* Forms list (pages/nuforms.php): right-click / long press on the status = menu to change the form's status (0.8.575).
   Saved through the form builder's API (action form.save, field sfo_status) – the same rights, draft rules and history as the builder. */
(function () {
	'use strict';
	var D = document.getElementById('nuFormStatusData');
	if (!D) return;
	var T = {};
	try { T = JSON.parse(D.getAttribute('data-t') || '{}'); } catch (e) { T = {}; }
	var CLS = {'1': 'bg-success-subtle text-success-emphasis', '0': 'bg-warning-subtle text-warning-emphasis', '2': 'bg-danger-subtle text-danger-emphasis', '3': 'bg-secondary-subtle text-secondary-emphasis'};
	var ICON = {'1': 'bi-check-circle', '0': 'bi-pencil', '2': 'bi-cone-striped', '3': 'bi-archive'};
	var menu = null, pressT = null, pressed = false;

	function closeMenu() { if (menu) { menu.remove(); menu = null; } }

	function toast(msg, ok) {
		var box = document.getElementById('nuFormStatusToast');
		if (!box) {
			box = document.createElement('div');
			box.id = 'nuFormStatusToast';
			box.className = 'position-fixed bottom-0 end-0 p-3 nuFormStatusToast';
			document.body.appendChild(box);
		}
		var a = document.createElement('div');
		a.className = 'alert py-2 px-3 small shadow-sm mb-2 ' + (ok ? 'alert-success' : 'alert-danger');
		a.setAttribute('role', ok ? 'status' : 'alert');
		a.textContent = msg;
		box.appendChild(a);
		setTimeout(function () { a.remove(); }, ok ? 2500 : 7000);
	}

	function show(el, st) {
		el.setAttribute('data-nu-st', st);
		var b = el.querySelector('.badge');
		if (b) { b.className = 'badge ' + CLS[st]; b.textContent = T[st] || st; }
	}

	function save(el, st, liveOk) {
		var body = {action: 'form.save', form_id: el.getAttribute('data-nu-form'), fields: {sfo_status: st}};
		if (liveOk) body.live_ok = true;
		el.classList.add('nuFormStatusBusy');
		fetch(D.getAttribute('data-url'), {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': D.getAttribute('data-csrf')},
			body: JSON.stringify(body)})
			.then(function (r) { return r.json().catch(function () { return {ok: false}; }); })
			.then(function (j) {
				el.classList.remove('nuFormStatusBusy');
				if (j.ok) { show(el, st); toast((T.done || '%s').replace('%s', T[st] || st), true); return; }
				// globeadmin, a draft is open: change the live form after a confirmation (as in the builder)
				if (j.code === 'draft_open_confirm' && !liveOk && window.nuConfirmAsk) { window.nuConfirmAsk(j.error, function () { save(el, st, true); }); return; }
				toast(j.error || T.err || '', false);
			}, function () { el.classList.remove('nuFormStatusBusy'); toast(T.err || '', false); });
	}

	// Maintenance (0.8.808): on = the dialog of assets/js/nu/nuformmaint.js (message, until); leaving it = maintenance off first, then the new status
	function maint(el, st) {
		var id = el.getAttribute('data-nu-form');
		if (st === '2') {
			if (window.nuFormMaintOpen) window.nuFormMaintOpen({forms: [{id: id, label: el.getAttribute('data-nu-label') || id}], onDone: function (ch) { if (ch.length) show(el, '2'); }});
			return;
		}
		var fd = document.getElementById('nuFmsData');
		fetch(fd.getAttribute('data-url'), {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': fd.getAttribute('data-csrf')},
			body: JSON.stringify({forms: [id], on: false})})
			.then(function (r) { return r.json().catch(function () { return {ok: false}; }); })
			.then(function (j) {
				if (!j.ok) { toast(j.error || j.message || T.err || '', false); return; }
				show(el, '1');
				if (st !== '1') save(el, st, false); else toast((T.done || '%s').replace('%s', T['1'] || '1'), true);
			}, function () { toast(T.err || '', false); });
	}

	function openMenu(el, x, y) {
		closeMenu();
		var cur = el.getAttribute('data-nu-st');
		menu = document.createElement('ul');
		menu.className = 'dropdown-menu show nuSbMenu';
		menu.setAttribute('role', 'menu');
		var head = document.createElement('li');
		head.innerHTML = '<h6 class="dropdown-header"></h6>';
		head.firstChild.textContent = T.head || '';
		menu.appendChild(head);
		['1', '0', '2', '3'].forEach(function (st) {
			if (st === '2' && (!window.nuFormMaintOpen || cur === '0' || cur === '3')) return;   // Draft / Archived: nobody but designers opens them anyway
			var li = document.createElement('li'), btn = document.createElement('button'), ic = document.createElement('i');
			btn.type = 'button';
			btn.className = 'dropdown-item' + (st === cur ? ' active' : '');
			btn.setAttribute('role', 'menuitemradio');
			btn.setAttribute('aria-checked', st === cur ? 'true' : 'false');
			ic.className = 'bi ' + ICON[st] + ' me-2';
			ic.setAttribute('aria-hidden', 'true');
			btn.appendChild(ic);
			btn.appendChild(document.createTextNode(T[st] || st));
			btn.addEventListener('click', function () { closeMenu(); if (st === cur) return; if (st === '2' || cur === '2') maint(el, st); else save(el, st, false); });
			li.appendChild(btn);
			menu.appendChild(li);
		});
		document.body.appendChild(menu);
		if (!x && !y) { var r = el.getBoundingClientRect(); x = r.left; y = r.bottom + 2; }   // context-menu key / Shift+F10
		menu.style.left = Math.max(4, Math.min(x, window.innerWidth - menu.offsetWidth - 4)) + 'px';
		menu.style.top = Math.max(4, Math.min(y, window.innerHeight - menu.offsetHeight - 4)) + 'px';
		(menu.querySelector('.dropdown-item.active') || menu.querySelector('.dropdown-item')).focus({preventScroll: true});
	}

	function target(e) { return e.target && e.target.closest ? e.target.closest('.nuFormStatus[data-nu-form]') : null; }

	document.addEventListener('contextmenu', function (e) {
		var el = target(e);
		if (!el) return;
		e.preventDefault();
		e.stopPropagation();
		if (pressed) return;
		openMenu(el, e.clientX, e.clientY);
	}, true);
	// touch: a long press opens the same menu and does not open the row
	document.addEventListener('pointerdown', function (e) {
		if (menu && !menu.contains(e.target)) closeMenu();
		var el = target(e);
		if (!el || e.pointerType !== 'touch') return;
		var x = e.clientX, y = e.clientY;
		pressed = false;
		pressT = setTimeout(function () { pressed = true; openMenu(el, x, y); }, 500);
	}, true);
	['pointerup', 'pointercancel'].forEach(function (ev) { document.addEventListener(ev, function () { clearTimeout(pressT); }, true); });
	document.addEventListener('click', function (e) {
		if (pressed && target(e)) { pressed = false; e.preventDefault(); e.stopPropagation(); }
	}, true);
	document.addEventListener('keydown', function (e) {
		if (menu && e.key === 'Escape') { e.stopPropagation(); closeMenu(); }
		var el = target(e);
		if (el && !menu && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); e.stopPropagation(); openMenu(el, 0, 0); }
	}, true);
	window.addEventListener('scroll', closeMenu, true);
	window.addEventListener('resize', closeMenu);
	window.addEventListener('blur', closeMenu);
})();
