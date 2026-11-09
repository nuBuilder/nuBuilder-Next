/* Projects page (pages/nuprojects.php): Add dialog search, Suggest related, note edit toggles, tab in the address. */
(function () {
	'use strict';
	var P = document.getElementById('prjPanes');
	if (!P) return;
	var url = P.getAttribute('data-url'), pid = P.getAttribute('data-project'), csrf = P.getAttribute('data-csrf');

	function post(data) {
		var fd = new FormData();
		fd.append('_csrf', csrf);
		fd.append('project', pid);
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(url, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-CSRF-Token': csrf}}).then(function (r) { return r.json(); });
	}
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}
	function row(value, label, sub, disabled, why) {
		var lab = el('label', 'list-group-item d-flex gap-2 align-items-start' + (disabled ? ' text-body-secondary' : ''));
		var cb = el('input', 'form-check-input mt-1 flex-shrink-0');
		cb.type = 'checkbox';
		cb.name = 'items[]';
		cb.value = value;
		cb.disabled = !!disabled;
		lab.appendChild(cb);
		var box = el('span', 'flex-grow-1');
		box.appendChild(el('span', '', label));
		if (sub) box.appendChild(el('span', 'd-block small text-body-secondary font-monospace', sub));
		if (why) box.appendChild(el('span', 'd-block small text-body-secondary', why));
		lab.appendChild(box);
		return lab;
	}
	function info(list, text) { list.textContent = ''; list.appendChild(el('div', 'list-group-item text-body-secondary', text)); }

	// tab in the address (?tab=), so a reload / Back opens it again
	document.querySelectorAll('#prjTabs [data-tab]').forEach(function (a) {
		a.addEventListener('shown.bs.tab', function () {
			try {
				var u = new URL(location.href);
				if (a.getAttribute('data-tab') === 'overview') u.searchParams.delete('tab'); else u.searchParams.set('tab', a.getAttribute('data-tab'));
				u.searchParams.delete('task');
				history.replaceState(history.state, '', u.toString());
			} catch (e) { /* old browser */ }
		});
	});

	// Add dialog: kind + search → candidates
	var addDlg = document.getElementById('prjAddDlg');
	if (addDlg) {
		var kind = document.getElementById('prjAddKind'), q = document.getElementById('prjAddQ'), list = document.getElementById('prjAddList'),
			count = document.getElementById('prjAddCount'), seq = 0, timer = 0, picked = {};
		var showCount = function () {
			var n = Object.keys(picked).length;
			count.textContent = n ? count.getAttribute('data-t').replace('%d', String(n)) : '';
			document.getElementById('prjAddGo').disabled = !n;
		};
		var load = function () {
			var my = ++seq;
			info(list, P.getAttribute('data-t-loading'));
			post({action: 'candidates', kind: kind.value, q: q.value.trim()}).then(function (r) {
				if (my !== seq) return;
				list.textContent = '';
				if (!r.items || !r.items.length) { info(list, P.getAttribute('data-t-none')); return; }
				r.items.forEach(function (c) {
					var v = kind.value + '|' + c.ref, it = row(v, c.label, c.sub, c.member, c.member ? P.getAttribute('data-t-member') : '');
					var cb = it.querySelector('input');
					cb.checked = !!picked[v];
					cb.addEventListener('change', function () { if (cb.checked) picked[v] = 1; else delete picked[v]; showCount(); });
					list.appendChild(it);
				});
			}).catch(function () { info(list, P.getAttribute('data-t-none')); });
		};
		kind.addEventListener('change', load);
		q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 250); });
		q.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
		addDlg.addEventListener('shown.bs.modal', function () { q.focus(); load(); showCount(); });
		// picks of other kinds / searches travel as hidden fields
		document.getElementById('prjAddForm').addEventListener('submit', function () {
			var form = this, shown = {};
			list.querySelectorAll('input[name="items[]"]').forEach(function (cb) { shown[cb.value] = 1; });
			Object.keys(picked).forEach(function (v) {
				if (shown[v]) return;
				var h = el('input');
				h.type = 'hidden';
				h.name = 'items[]';
				h.value = v;
				form.appendChild(h);
			});
		});
	}

	// Suggest related
	var sugDlg = document.getElementById('prjSuggestDlg');
	if (sugDlg) {
		var sList = document.getElementById('prjSuggestList'), all = document.getElementById('prjSuggestAll'), go = document.getElementById('prjSuggestGo');
		var sync = function () {
			var cbs = sList.querySelectorAll('input[name="items[]"]:not(:disabled)'), on = 0;
			cbs.forEach(function (c) { if (c.checked) on++; });
			all.checked = cbs.length > 0 && on === cbs.length;
			all.indeterminate = on > 0 && on < cbs.length;
			all.disabled = !cbs.length;
			go.disabled = !on;
		};
		sugDlg.addEventListener('shown.bs.modal', function () {
			info(sList, P.getAttribute('data-t-loading'));
			go.disabled = true;
			post({action: 'suggest'}).then(function (r) {
				sList.textContent = '';
				if (!r.items || !r.items.length) { info(sList, P.getAttribute('data-t-none')); sync(); return; }
				var last = '';
				r.items.forEach(function (s) {
					if (s.kind !== last) {
						last = s.kind;
						sList.appendChild(el('div', 'list-group-item fw-semibold small bg-body-tertiary', (r.kinds && r.kinds[s.kind]) || s.kind));
					}
					var it = row(s.kind + '|' + s.ref, s.label, s.sub || '', false, s.why && s.why.length ? P.getAttribute('data-t-why') + ' ' + [].concat(s.why).join(', ') : '');
					it.querySelector('input').checked = true;
					it.querySelector('input').addEventListener('change', sync);
					sList.appendChild(it);
				});
				sync();
			}).catch(function () { info(sList, P.getAttribute('data-t-none')); });
		});
		all.addEventListener('change', function () {
			sList.querySelectorAll('input[name="items[]"]:not(:disabled)').forEach(function (c) { c.checked = all.checked; });
			sync();
		});
	}

	// notes: pencil shows the edit form in place
	document.querySelectorAll('.prjNoteEdit').forEach(function (b) {
		b.addEventListener('click', function () {
			var card = document.getElementById('prjNote-' + b.getAttribute('data-note'));
			if (!card) return;
			var box = card.querySelector('.prjNoteEditBox'), view = card.querySelector('.prjNoteView');
			var open = box.hidden;
			box.hidden = !open;
			view.hidden = open;
			if (open) { var t = box.querySelector('textarea'); if (t) t.focus(); }
		});
	});

	// an edit link of a to-do opens the form
	if (location.hash === '#prjTaskEdit') {
		var d = document.getElementById('prjTaskEdit');
		if (d) { d.open = true; d.scrollIntoView({block: 'start'}); }
	}
}());
