/* global nuBs */
/* The debug icon of the top bar (core/nulayout.php nu_debug_bell(), pages/nudebugbell.php, core/nudebugbell.php).
   Hidden while there are no new Debug results; checks every 60 s (not while the tab is in the background, at once when it comes back)
   and shortly after every server call of a form (event nu:formapi – button actions, procedures); the panel lists the newest rows,
   "Mark as read" hides the icon again. */
(function () {
	'use strict';
	var box = document.getElementById('nuDbg');
	if (!box) return;
	var T = {};
	try { T = JSON.parse(box.getAttribute('data-t') || '{}'); } catch (e) { T = {}; }
	function t(k, n) { return (T[k] || k).replace('%d', n); }
	var url = box.getAttribute('data-url'), btn = document.getElementById('nuDbgBtn');
	var badge = box.querySelector('.nuDbgBadge'), list = box.querySelector('.nuDbgList'), count = box.querySelector('.nuDbgCount');
	var busy = false, soon = 0, shown = !box.hidden;

	function set(n) {
		n = n || 0;
		badge.textContent = n > 99 ? '99+' : String(n);
		count.textContent = n ? t('dbg_new_n', n) : t('dbg_nothing_new');
		var label = t('dbg_bell_n', n);
		btn.setAttribute('aria-label', label); btn.title = label;
		var open = box.querySelector('.dropdown-menu.show');
		if (!n && !open) box.hidden = true;
		if (n) {
			box.hidden = false;
			if (!shown) { btn.classList.remove('nuBellRing'); void btn.offsetWidth; btn.classList.add('nuBellRing'); }
		}
		shown = !box.hidden;
	}

	function draw(items) {
		list.textContent = '';
		if (!items || !items.length) { list.appendChild(NuUtil.el('div', 'px-3 py-4 text-center small text-body-secondary', t('dbg_none'))); return; }
		items.forEach(function (it) {
			var row = NuUtil.el('a', 'nuDbgItem' + (it.new ? ' nuDbgNew' : ''));
			row.href = document.getElementById('nuDbgOpen').href;
			row.appendChild(NuUtil.el('time', 'nuDbgAt', it.at));
			var main = NuUtil.el('span', 'nuDbgMain');
			main.appendChild(NuUtil.el('span', 'nuDbgTitle', it.title));
			if (it.text) main.appendChild(NuUtil.el('code', 'nuDbgText', it.text));
			row.appendChild(main);
			list.appendChild(row);
		});
	}

	function check(withList) {
		if (busy || document.hidden) return Promise.resolve();
		busy = true;
		return fetch(url + (withList ? '&list=1' : ''), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (d) { if (d && d.ok) { set(d.n); if (d.items) draw(d.items); } })
			.catch(function () { /* next time */ })
			.then(function () { busy = false; });
	}

	btn.addEventListener('show.bs.dropdown', function () { check(true); });
	btn.addEventListener('hidden.bs.dropdown', function () { if (badge.textContent === '0') box.hidden = true; shown = !box.hidden; });
	box.querySelector('.nuDbgRead').addEventListener('click', function () {
		NuUtil.post(url, {action: 'read'}).then(function (d) {
			if (!d || !d.ok) return;
			if (window.nuBs && nuBs.ready()) nuBs.dropdown(btn).hide();
			set(0); box.hidden = true; shown = false;
		});
	});
	document.addEventListener('nu:formapi', function () { window.clearTimeout(soon); soon = window.setTimeout(function () { check(false); }, 400); });
	document.addEventListener('visibilitychange', function () { if (!document.hidden) check(false); });
	window.setInterval(function () { check(false); }, 60000);
	window.nuDebugBellCheck = check;
})();
