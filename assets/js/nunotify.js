/* Notification centre – the bell in the top bar (0.8.357; core/nulayout.php nu_bell(), pages/nunotifyapi.php, core/nunotify.php).
   Checks every data-poll seconds (not while the tab is in the background; at once when it comes back), red badge = unread,
   the panel lists the latest notifications (mark read / unread, hide, open the link), a toast for new ones, and a dialog for
   notifications that must be confirmed ("Got it"). */
(function () {
	'use strict';
	var box = document.getElementById('nuBell');
	if (!box) return;
	var T = {};
	try { T = JSON.parse(box.getAttribute('data-t') || '{}'); } catch (e) { T = {}; }
	function t(k) { return T[k] || k; }
	var url = box.getAttribute('data-url'), csrf = box.getAttribute('data-csrf'), poll = Math.max(20, parseInt(box.getAttribute('data-poll'), 10) || 60) * 1000;
	var btn = document.getElementById('nuBellBtn'), badge = box.querySelector('.nuBellBadge'), list = box.querySelector('.nuBellList'), allBtn = box.querySelector('.nuBellAll');
	var timer = 0, busy = false, confirmOpen = false, last = null;

	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
	function icon(cls) { var i = el('i', 'bi ' + cls); i.setAttribute('aria-hidden', 'true'); return i; }
	function seen(ids) {                                  // ids already announced by a toast in this browser session
		try {
			if (ids === undefined) { var v = sessionStorage.getItem('nunext.ntfSeen'); return v === null ? null : JSON.parse(v); }
			sessionStorage.setItem('nunext.ntfSeen', JSON.stringify(ids.slice(-200)));
		} catch (e) { return null; }
		return null;
	}

	function request(body) {
		var o = {credentials: 'same-origin', headers: {'Accept': 'application/json'}};
		if (body) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-Token'] = csrf; o.body = JSON.stringify(body); }
		return fetch(url, o).then(function (r) { if (r.status === 401) { stop(); throw new Error('login'); } return r.json(); });
	}

	function setBadge(n) {
		badge.hidden = !n;
		badge.textContent = n > 99 ? '99+' : String(n);
		btn.classList.toggle('nuBellHas', n > 0);
		btn.querySelector('i').className = 'bi ' + (n ? 'bi-bell-fill' : 'bi-bell');
		var label = n ? t('ntf_unread_n').replace('%d', n) : t('ntf_title');
		btn.setAttribute('aria-label', label); btn.title = label;
		allBtn.hidden = !n;
	}

	function action(a, id) {
		return request({action: a, id: id}).then(apply).catch(function () { /* next check */ });
	}

	function itemEl(it) {
		var li = el('div', 'nuBellItem nuBellLv-' + it.level + (it.read ? '' : ' nuBellUnread'));
		var head = el('div', 'd-flex align-items-start gap-2');
		head.appendChild(icon(it.icon + ' nuBellIcon'));
		var main = el('div', 'flex-grow-1 min-w-0');
		var title = el('div', 'nuBellTitle');
		if (it.pinned) { var pin = icon('bi-pin-angle-fill me-1 text-body-secondary'); pin.title = t('ntf_pinned'); title.appendChild(pin); }
		title.appendChild(document.createTextNode(it.title));
		main.appendChild(title);
		if (it.text) { var b = el('div', 'nuBellBody small'); b.innerHTML = it.body; main.appendChild(b); }   // escaped on the server (NuNotify::bodyHtml)
		var meta = el('div', 'small text-body-secondary d-flex flex-wrap gap-2 align-items-center mt-1');
		meta.appendChild(el('span', '', it.ago));
		if (it.link) {
			var a = el('a', 'text-decoration-none', t('ntf_open'));
			a.href = it.link;
			if (/^https?:/i.test(it.link)) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
			a.addEventListener('click', function () { if (!it.read) action('read', it.id); });
			meta.appendChild(a);
		}
		main.appendChild(meta);
		head.appendChild(main);
		var tools = el('div', 'nuBellTools d-flex flex-column gap-1');
		var rb = el('button', 'btn btn-sm nuBellTool');
		rb.type = 'button'; rb.title = it.read ? t('ntf_mark_unread') : t('ntf_mark_read'); rb.setAttribute('aria-label', rb.title);
		rb.appendChild(icon(it.read ? 'bi-envelope' : 'bi-envelope-open'));
		rb.addEventListener('click', function () { action(it.read ? 'unread' : 'read', it.id); });
		var hb = el('button', 'btn btn-sm nuBellTool');
		hb.type = 'button'; hb.title = t('ntf_hide'); hb.setAttribute('aria-label', t('ntf_hide'));
		hb.appendChild(icon('bi-x-lg'));
		hb.addEventListener('click', function () { action('hide', it.id); });
		tools.appendChild(rb); tools.appendChild(hb);
		head.appendChild(tools);
		li.appendChild(head);
		return li;
	}

	function render(d) {
		list.textContent = '';
		if (!d.items || !d.items.length) { list.appendChild(el('div', 'px-3 py-4 text-center small text-body-secondary', t('ntf_none'))); return; }
		d.items.forEach(function (it) { list.appendChild(itemEl(it)); });
	}

	function toast(it) {
		if (!window.nuBs || !nuBs.ready()) return;
		var holder = document.getElementById('nuBellToasts');
		if (!holder) { holder = el('div', 'toast-container position-fixed top-0 end-0 p-3 nuBellToasts'); holder.id = 'nuBellToasts'; document.body.appendChild(holder); }
		var tst = el('div', 'toast nuBellToast nuBellLv-' + it.level);
		tst.setAttribute('role', 'status'); tst.setAttribute('aria-live', 'polite');
		var h = el('div', 'toast-header gap-2');
		h.appendChild(icon(it.icon + ' nuBellIcon'));
		h.appendChild(el('strong', 'me-auto text-truncate', it.title));
		h.appendChild(el('small', 'text-body-secondary', t('ntf_new_toast')));
		var x = el('button', 'btn-close'); x.type = 'button'; x.setAttribute('data-bs-dismiss', 'toast'); x.setAttribute('aria-label', t('close'));
		h.appendChild(x);
		tst.appendChild(h);
		tst.addEventListener('click', function (e) { if (e.target !== x) { nuBs.dropdown(btn).show(); } });
		holder.appendChild(tst);
		tst.addEventListener('hidden.bs.toast', function () { tst.remove(); });
		nuBs.toast(tst, {delay: 8000}).show();
		btn.classList.remove('nuBellRing'); void btn.offsetWidth; btn.classList.add('nuBellRing');
	}

	function confirmDialog(it) {
		if (confirmOpen || !window.nuBs || !nuBs.ready()) return;
		confirmOpen = true;
		var m = el('div', 'modal fade nuBellConfirm');
		m.tabIndex = -1; m.setAttribute('aria-modal', 'true'); m.setAttribute('role', 'dialog'); m.setAttribute('data-bs-backdrop', 'static'); m.setAttribute('data-bs-keyboard', 'false');
		var dlg = el('div', 'modal-dialog modal-dialog-centered'), c = el('div', 'modal-content nuBellLv-' + it.level);
		var hd = el('div', 'modal-header'), ti = el('h2', 'modal-title fs-5 d-flex align-items-center gap-2');
		ti.appendChild(icon(it.icon + ' nuBellIcon')); ti.appendChild(document.createTextNode(it.title)); hd.appendChild(ti);
		var bd = el('div', 'modal-body');
		if (it.text) { var b = el('div'); b.innerHTML = it.body; bd.appendChild(b); }
		if (it.link) { var a = el('a', 'd-inline-block mt-2', t('ntf_open')); a.href = it.link; if (/^https?:/i.test(it.link)) { a.target = '_blank'; a.rel = 'noopener noreferrer'; } bd.appendChild(a); }
		bd.appendChild(el('div', 'small text-body-secondary mt-3', t('ntf_must_confirm') + ' · ' + it.ago + (it.by ? ' · ' + it.by : '')));
		var ft = el('div', 'modal-footer'), ok = el('button', 'btn btn-primary', t('ntf_got_it'));
		ok.type = 'button';
		ok.addEventListener('click', function () {
			ok.disabled = true;
			request({action: 'confirm', id: it.id}).then(function (d) { nuBs.modal(m).hide(); m.addEventListener('hidden.bs.modal', function () { m.remove(); confirmOpen = false; apply(d); }, {once: true}); })
				.catch(function () { ok.disabled = false; });
		});
		ft.appendChild(ok);
		c.appendChild(hd); c.appendChild(bd); c.appendChild(ft); dlg.appendChild(c); m.appendChild(dlg);
		document.body.appendChild(m);
		nuBs.modal(m).show();
	}

	function apply(d) {
		if (!d || !d.ok) return;
		last = d;
		setBadge(d.unread || 0);
		if (box.querySelector('.dropdown-menu.show')) render(d);
		var known = seen();
		var ids = d.unread_ids || [];
		if (known === null) seen(ids);                    // first check in this browser session: no toasts for what is already there
		else {
			var fresh = ids.filter(function (i) { return known.indexOf(i) < 0; });
			if (fresh.length) {
				(d.items || []).filter(function (it) { return fresh.indexOf(it.id) >= 0 && !it.confirm; }).slice(0, 3).forEach(toast);
				seen(known.concat(fresh));
			}
		}
		if (d.confirm && d.confirm.length) confirmDialog(d.confirm[0]);
	}

	function check() {
		if (busy || document.hidden) return;
		busy = true;
		request(null).then(apply).catch(function () { /* offline – next time */ }).then(function () { busy = false; });
	}
	function stop() { window.clearInterval(timer); timer = 0; }

	btn.addEventListener('show.bs.dropdown', function () {
		if (last) render(last);
		request(null).then(function (d) { apply(d); render(d); }).catch(function () { /* keep */ });
	});
	allBtn.addEventListener('click', function () { request({action: 'readall'}).then(function (d) { apply(d); render(d); }).catch(function () { /* next check */ }); });
	document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
	check();
	timer = window.setInterval(check, poll);
})();
