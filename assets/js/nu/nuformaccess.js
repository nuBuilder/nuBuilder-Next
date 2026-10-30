/*
 * Access levels of one form (0.8.683): window.nuFormAccessOpen([formId]) – a dialog listing every access level with Open (the level may use
 * the form) and its buttons (Add, Save, Delete, Clone, New, Print), data mode and form type. Opened from the form's ⋮ menu
 * (nuruntime.js shortcutActions, tools.formaccess) and from design mode (Form panel → Tools). Reads / writes through the JSON route
 * ?p=formaccess (pages/nuformaccess.php) – only the levels that were changed are sent. No inline styles or scripts (CSP).
 */
(function () {
	'use strict';
	var N = window.nuNext || {};
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined && text !== null) e.textContent = text; return e; }
	function url(q) { return (N.base || 'index.php') + '?p=formaccess' + (q || ''); }
	// own pages without window.nuNext (the forms list, 0.8.688): the token from #nuFacData
	function csrf() { var d = document.getElementById('nuFacData'); return N.csrf || (d ? d.getAttribute('data-csrf') : '') || ''; }
	function toast(msg, type) { if (window.nuToast) window.nuToast(msg, type); else if (type === 'danger') window.alert(msg); }

	function open(formId) {
		formId = formId || (N.form && N.form.id) || '';
		if (!formId || !(window.nuBs && window.nuBs.ready())) return;
		fetch(url('&f=' + encodeURIComponent(formId)), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (d) { if (!d || !d.ok) { toast((d && d.message) || 'Error', 'danger'); return; } show(d); })
			.catch(function () { toast('Error', 'danger'); });
	}

	function show(d) {
		var T = d.texts, start = {}, rows = [];
		var old = document.getElementById('nuFormAccessDlg');
		if (old) old.remove();
		var dlg = el('div', 'modal fade'); dlg.id = 'nuFormAccessDlg'; dlg.tabIndex = -1; dlg.setAttribute('aria-labelledby', 'nuFacTitle');
		var md = el('div', 'modal-dialog modal-xl modal-dialog-scrollable'), mc = el('div', 'modal-content');
		var head = el('div', 'modal-header'), h = el('h2', 'modal-title fs-5', T.fac_title); h.id = 'nuFacTitle';
		h.appendChild(el('span', 'text-body-secondary fw-normal ms-2', d.form.label));
		var x = el('button', 'btn-close'); x.type = 'button'; x.setAttribute('data-bs-dismiss', 'modal'); x.setAttribute('aria-label', T.cancel);
		head.appendChild(h); head.appendChild(x);
		var body = el('div', 'modal-body');
		body.appendChild(el('p', 'small text-body-secondary mb-2', T.fac_intro));
		if (d.admin_form) body.appendChild(el('div', 'alert alert-warning small py-2', T.fac_admin));
		// toolbar: search, group filter, Select all, count
		var bar = el('div', 'd-flex flex-wrap align-items-center gap-2 mb-2 nuFacBar');
		var q = el('input', 'form-control form-control-sm nuFacSearch'); q.type = 'search'; q.id = 'nuFacSearch'; q.placeholder = T.fac_search; q.setAttribute('aria-label', T.fac_search);
		bar.appendChild(q);
		var groups = [];
		d.levels.forEach(function (l) { if (groups.indexOf(l.group) < 0) groups.push(l.group); });
		var gsel = null;
		if (groups.length > 1) {
			gsel = el('select', 'form-select form-select-sm w-auto'); gsel.id = 'nuFacGroup'; gsel.setAttribute('aria-label', T.fac_group);
			gsel.appendChild(new Option(T.fac_groups, '*'));
			groups.sort().forEach(function (g) { gsel.appendChild(new Option(g === '' ? T.fac_nogroup : g, g)); });
			bar.appendChild(gsel);
		}
		var allWrap = el('div', 'form-check mb-0 ms-1'), all = el('input', 'form-check-input'); all.type = 'checkbox'; all.id = 'nuFacAll';
		var allL = el('label', 'form-check-label small', T.fac_all); allL.htmlFor = 'nuFacAll';
		allWrap.appendChild(all); allWrap.appendChild(allL); bar.appendChild(allWrap);
		var count = el('span', 'small text-body-secondary ms-auto'); count.id = 'nuFacCount'; bar.appendChild(count);
		body.appendChild(bar);
		// the table
		var wrap = el('div', 'table-responsive'), t = el('table', 'table table-sm align-middle mb-0 nu-table nuFacTable'); t.id = 'nuFacTable';
		var thead = el('thead'), htr = el('tr');
		// sortable titles (0.8.696): a button per column – level code, Open / each right (ticked first), data mode, form type
		var sortTh = function (cls, key, text) {
			var th = el('th', (cls ? cls + ' ' : '') + 'nuFacSort'); th.setAttribute('data-sort', key);
			var b = el('button', 'btn btn-link p-0 userPermSortBtn' + (key.indexOf('r:') === 0 ? ' small' : ''), text); b.type = 'button';
			th.appendChild(b); return th;
		};
		htr.appendChild(sortTh('', 'level', T.fac_level));
		htr.appendChild(sortTh('text-center', 'open', T.fac_open));
		d.rights.forEach(function (r) {
			var th = sortTh('text-center', 'r:' + r, T['right_' + r]);
			var cb = el('input', 'form-check-input nuFacCol d-block mx-auto mt-1'); cb.type = 'checkbox'; cb.setAttribute('data-col', r); cb.setAttribute('aria-label', T.toggle_all + ': ' + T['right_' + r]);
			th.appendChild(cb); htr.appendChild(th);
		});
		if (d.dm) htr.appendChild(sortTh('', 'dm', T.access_col_dm));
		if (d.ft) htr.appendChild(sortTh('', 'ft', T.access_col_ft));
		htr.appendChild(el('th', ''));
		thead.appendChild(htr); t.appendChild(thead);
		var tb = el('tbody');
		if (!d.levels.length) { var tr0 = el('tr'), td0 = el('td', 'text-center text-body-secondary py-4', T.fac_none); td0.colSpan = 3 + d.rights.length; tr0.appendChild(td0); tb.appendChild(tr0); }
		var selOf = function (opts, cur, aria) {
			var s = el('select', 'form-select form-select-sm'); s.setAttribute('aria-label', aria);
			opts.forEach(function (o) { var op = new Option(o[1], o[0]); if (o[0] === cur) op.selected = true; s.appendChild(op); });
			return s;
		};
		d.levels.forEach(function (l) {
			var tr = el('tr'), lock = !d.can_save || l.own;
			tr.setAttribute('data-level', l.id); tr.setAttribute('data-group', l.group);
			tr.setAttribute('data-search', (l.code + ' ' + l.description + ' ' + l.group).toLowerCase());
			var td = el('td'); td.appendChild(el('div', 'fw-semibold', l.code));
			if (l.description) td.appendChild(el('div', 'small text-body-secondary', l.description));
			if (l.own) { var ow = el('div', 'small text-warning-emphasis', T.fac_own); td.appendChild(ow); }
			tr.appendChild(td);
			var cell = function (cb, cls) { var c = el('td', 'text-center ' + (cls || '')); c.appendChild(cb); tr.appendChild(c); return cb; };
			var on = el('input', 'form-check-input nuFacOpen'); on.type = 'checkbox'; on.checked = !!l.row; on.disabled = lock; on.setAttribute('aria-label', T.fac_open + ': ' + l.code);
			cell(on);
			var boxes = {};
			d.rights.forEach(function (r) {
				var cb = el('input', 'form-check-input nuFacRight'); cb.type = 'checkbox'; cb.setAttribute('data-right', r);
				cb.checked = !!(l.row && l.row.rights[r]); cb.disabled = lock || !l.row; cb.setAttribute('aria-label', T['right_' + r] + ': ' + l.code);
				boxes[r] = cell(cb);
			});
			var dm = null, ft = null;
			if (d.dm) { dm = selOf([['', T.access_dm_normal], ['0', T.access_dm_noedit]], l.row ? l.row.dm : '', T.access_col_dm + ': ' + l.code); dm.disabled = lock || !l.row; var c1 = el('td'); c1.appendChild(dm); tr.appendChild(c1); }
			if (d.ft) { ft = selOf([['', T.access_ft_both], ['0', T.access_ft_browse], ['1', T.access_ft_edit]], l.row ? l.row.ft : '', T.access_col_ft + ': ' + l.code); ft.disabled = lock || !l.row; var c2 = el('td'); c2.appendChild(ft); tr.appendChild(c2); }
			var lk = el('td', 'text-end'), a = el('a', 'btn btn-sm btn-link p-0');
			a.href = d.page.replace('__ID__', encodeURIComponent(l.id)); a.target = '_blank'; a.rel = 'noopener'; a.title = T.fac_page; a.setAttribute('aria-label', T.fac_page + ': ' + l.code);
			var ic = el('i', 'bi bi-box-arrow-up-right'); ic.setAttribute('aria-hidden', 'true'); a.appendChild(ic); lk.appendChild(a); tr.appendChild(lk);
			var row = {l: l, tr: tr, on: on, boxes: boxes, dm: dm, ft: ft, lock: lock};
			row.state = function () {
				if (!on.checked) return null;
				var s = {rights: {}, dm: dm ? dm.value : '', ft: ft ? ft.value : ''};
				d.rights.forEach(function (r) { s.rights[r] = boxes[r].checked; });
				return s;
			};
			on.addEventListener('change', function () {
				var en = on.checked && !lock;
				d.rights.forEach(function (r) { boxes[r].disabled = !en; });   // a level that gets the form: open only, the buttons are ticked one by one
				if (dm) dm.disabled = !en; if (ft) ft.disabled = !en;
				row.touched = true; sync();
			});
			Object.keys(boxes).forEach(function (r) { boxes[r].addEventListener('change', function () { row.touched = true; sync(); }); });
			rows.push(row); start[l.id] = JSON.stringify(row.state());
			tb.appendChild(tr);
		});
		t.appendChild(tb); wrap.appendChild(t); body.appendChild(wrap);
		var foot = el('div', 'modal-footer');
		var cancel = el('button', 'btn btn-outline-secondary', T.cancel); cancel.type = 'button'; cancel.setAttribute('data-bs-dismiss', 'modal');
		var save = el('button', 'btn btn-primary', T.save); save.type = 'button'; save.id = 'nuFacSave';
		foot.appendChild(cancel); if (d.can_save) foot.appendChild(save);
		mc.appendChild(head); mc.appendChild(body); mc.appendChild(foot); md.appendChild(mc); dlg.appendChild(md);
		document.body.appendChild(dlg);

		var visible = function () { return rows.filter(function (r) { return !r.tr.hidden; }); };
		function sync() {
			var n = rows.filter(function (r) { return r.on.checked; }).length;
			count.textContent = T.fac_count.replace('%d', n).replace('%d', d.levels.length);
			var vis = visible().filter(function (r) { return !r.lock; }), onN = vis.filter(function (r) { return r.on.checked; }).length;
			all.checked = vis.length > 0 && onN === vis.length; all.indeterminate = onN > 0 && onN < vis.length; all.disabled = !vis.length;
			d.rights.forEach(function (r) {
				var col = t.querySelector('.nuFacCol[data-col="' + r + '"]'), act = vis.filter(function (x) { return x.on.checked; });
				var c = act.filter(function (x) { return x.boxes[r].checked; }).length;
				col.checked = act.length > 0 && c === act.length; col.indeterminate = c > 0 && c < act.length; col.disabled = !act.length;
			});
			save.disabled = !changes().length;
		}
		function changes() { return rows.filter(function (r) { return JSON.stringify(r.state()) !== start[r.l.id]; }); }
		function filter() {
			var s = q.value.trim().toLowerCase(), g = gsel ? gsel.value : '*';
			rows.forEach(function (r) { r.tr.hidden = (s !== '' && r.tr.getAttribute('data-search').indexOf(s) < 0) || (g !== '*' && r.l.group !== g); });
			sync();
		}
		// sort (0.8.696): by the clicked column, then by level code; a second click reverses
		var cmp = function (a, b) { return a.localeCompare(b, undefined, {sensitivity: 'base', numeric: true}); };
		var keyOf = function (r, k) {
			if (k === 'level') return r.l.code;
			if (k === 'open') return r.on.checked ? '0' : '1';
			if (k.indexOf('r:') === 0) return r.on.checked && r.boxes[k.slice(2)].checked ? '0' : '1';
			var s = r[k]; return s && r.on.checked ? s.options[s.selectedIndex].text : '\uffff';
		};
		t.querySelectorAll('th.nuFacSort').forEach(function (th) {
			th.querySelector('button').addEventListener('click', function () {
				var k = th.getAttribute('data-sort'), dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
				rows.slice().sort(function (a, b) { return cmp(keyOf(a, k), keyOf(b, k)) * dir || cmp(a.l.code, b.l.code); }).forEach(function (r) { tb.appendChild(r.tr); });
				t.querySelectorAll('th.nuFacSort').forEach(function (o) { o.removeAttribute('aria-sort'); });
				th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
			});
		});
		q.addEventListener('input', filter);
		if (gsel) gsel.addEventListener('change', filter);
		all.addEventListener('change', function () {
			visible().forEach(function (r) { if (!r.lock && r.on.checked !== all.checked) { r.on.checked = all.checked; r.on.dispatchEvent(new Event('change')); } });
			sync();
		});
		t.querySelectorAll('.nuFacCol').forEach(function (col) {
			col.addEventListener('change', function () {
				var r = col.getAttribute('data-col');
				visible().forEach(function (x) { if (!x.lock && x.on.checked) x.boxes[r].checked = col.checked; });
				sync();
			});
		});
		save.addEventListener('click', function () {
			var ch = changes(), levels = {};
			if (!ch.length) return;
			ch.forEach(function (r) { levels[r.l.id] = r.state(); });
			save.disabled = true;
			fetch(url(), {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf()},
				body: JSON.stringify({f: d.form.id, levels: levels})})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.ok) { toast((res && res.message) || 'Error', 'danger'); save.disabled = false; return; }
					toast(res.message, 'success');
					window.nuBs.modal(dlg).hide();
					if (!(N.form && N.form.id) && res.changed) window.location.reload();   // a list (forms list): new counts
				})
				.catch(function () { toast('Error', 'danger'); save.disabled = false; });
		});
		dlg.addEventListener('hidden.bs.modal', function () { dlg.remove(); });
		filter();
		window.nuBs.modal(dlg).show();
		dlg.addEventListener('shown.bs.modal', function () { q.focus(); }, {once: true});
	}
	window.nuFormAccessOpen = open;
	// [data-nu-fac="<form id>"] opens the dialog (the forms list's column Access levels, 0.8.688)
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-nu-fac]');
		if (!b) return;
		e.preventDefault();
		open(b.getAttribute('data-nu-fac'));
	});
})();
