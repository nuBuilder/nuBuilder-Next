/*
 * List views (0.8.329, core/nulistview.php): the editor of a view (columns, phone card, filters, general) and the import dialog.
 * Data from <div id="nuLvData" data-lv="…">; saves with POST index.php?p=listview (action save / delete / import, JSON answer).
 */
(function () {
	'use strict';
	var holder = document.getElementById('nuLvData');
	if (!holder) return;
	var D = JSON.parse(holder.getAttribute('data-lv') || '{}');
	var T = D.texts || {};
	var csrf = (holder.querySelector('input[name="_csrf"]') || {}).value || '';
	var esc = function (s) { return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
	var colLabel = {};
	D.columns.forEach(function (c) { colLabel[c.key] = c.label; });
	var uid = 0;

	function post(data, file) {
		var fd = new FormData();
		fd.append('_csrf', csrf); fd.append('list', D.list);
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		if (file) fd.append('file', file);
		return fetch(D.post, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (j) { if (!j.ok) throw new Error(j.error || 'Error'); return j; });
	}
	function sel(options, value, cls) {
		return '<select class="form-select form-select-sm ' + (cls || '') + '">' + options.map(function (o) {
			return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
		}).join('') + '</select>';
	}
	function modal(title, body, footer, size) {
		var id = 'nuLvModal' + (++uid);
		var el = document.createElement('div');
		el.className = 'modal fade nuLvModal';
		el.id = id; el.tabIndex = -1;
		el.setAttribute('aria-labelledby', id + 'T');
		el.innerHTML = '<div class="modal-dialog modal-dialog-scrollable ' + (size || 'modal-lg') + '"><div class="modal-content">'
			+ '<div class="modal-header"><h2 class="modal-title h5" id="' + id + 'T">' + esc(title) + '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' + esc(T.cancel) + '"></button></div>'
			+ '<div class="modal-body">' + body + '</div><div class="modal-footer">' + footer + '</div></div></div>';
		document.body.appendChild(el);
		el.addEventListener('hidden.bs.modal', function () { el.remove(); });
		var m = nuBs.modal(el);
		m.show();
		return el;
	}
	/** Up / down buttons and drag & drop for the rows of a list. */
	function sortable(tbody) {
		var dragged = null;
		tbody.addEventListener('click', function (e) {
			var b = e.target.closest('[data-lv-move]');
			if (!b) return;
			var tr = b.closest('tr');
			if (b.getAttribute('data-lv-move') === 'up' && tr.previousElementSibling) tbody.insertBefore(tr, tr.previousElementSibling);
			if (b.getAttribute('data-lv-move') === 'down' && tr.nextElementSibling) tbody.insertBefore(tr.nextElementSibling, tr);
			b.focus();
		});
		tbody.addEventListener('dragstart', function (e) { dragged = e.target.closest('tr'); if (dragged) { dragged.classList.add('nuLvDragging'); e.dataTransfer.effectAllowed = 'move'; } });
		tbody.addEventListener('dragend', function () { if (dragged) dragged.classList.remove('nuLvDragging'); dragged = null; });
		tbody.addEventListener('dragover', function (e) {
			if (!dragged) return;
			e.preventDefault();
			var tr = e.target.closest('tr');
			if (!tr || tr === dragged) return;
			var r = tr.getBoundingClientRect();
			tbody.insertBefore(dragged, e.clientY > r.top + r.height / 2 ? tr.nextElementSibling : tr);
		});
	}
	var moveBtns = function () {
		return '<button type="button" class="btn btn-sm btn-link p-0 me-1" data-lv-move="up" title="' + esc(T.lv_up) + '" aria-label="' + esc(T.lv_up) + '"><i class="bi bi-arrow-up"></i></button>'
			+ '<button type="button" class="btn btn-sm btn-link p-0" data-lv-move="down" title="' + esc(T.lv_down) + '" aria-label="' + esc(T.lv_down) + '"><i class="bi bi-arrow-down"></i></button>';
	};

	// ------------------------------------------------------------------ editor
	function editor(mode) {
		var V = D.view, d = JSON.parse(JSON.stringify(V.def));
		var isNew = mode === 'new';
		var name = isNew ? (T.lv_copy_of || '%s').replace('%s', V.name) : V.name;
		var scope = isNew ? 'user' : (V.scope === 'shared' ? 'shared' : 'user');
		var levels = isNew ? [] : (V.levels || []);
		var shown = d.columns.map(function (c) { return c.key; });
		var colRows = d.columns.concat(D.columns.filter(function (c) { return shown.indexOf(c.key) === -1; }).map(function (c) { return {key: c.key, width: 'auto', align: 'auto', hidden: true}; }));
		var widthOpts = [['auto', T.lv_width_auto], ['narrow', T.lv_width_narrow], ['wide', T.lv_width_wide]];
		var alignOpts = [['auto', T.lv_align_auto], ['start', T.lv_align_start], ['center', T.lv_align_center], ['end', T.lv_align_end]];
		var lockedOf = {};
		D.columns.forEach(function (c) { lockedOf[c.key] = c.locked; });

		var tabs = [['cols', T.lv_tab_columns], ['phone', T.lv_tab_phone], ['filters', T.lv_tab_filters], ['general', T.lv_tab_general]];
		var p = 'nuLvE' + (++uid);
		var html = '<div class="mb-3"><label class="form-label" for="' + p + 'name">' + esc(T.lv_name) + ' <span class="text-danger">*</span></label><input type="text" class="form-control lvName" id="' + p + 'name" maxlength="100" value="' + esc(name) + '"></div>'
			+ '<ul class="nav nav-tabs mb-3" role="tablist">' + tabs.map(function (t, i) {
			return '<li class="nav-item" role="presentation"><button class="nav-link' + (i ? '' : ' active') + '" type="button" role="tab" data-bs-toggle="tab" data-bs-target="#' + p + t[0] + '" aria-controls="' + p + t[0] + '" aria-selected="' + (i ? 'false' : 'true') + '">' + esc(t[1]) + '</button></li>';
		}).join('') + '</ul><div class="tab-content">';
		// columns
		html += '<div class="tab-pane fade show active" id="' + p + 'cols" role="tabpanel"><div class="table-responsive"><table class="table table-sm align-middle nuLvEdTable"><thead><tr><th></th><th>' + esc(T.lv_visible) + '</th><th>' + esc(T.lv_width) + '</th><th>' + esc(T.lv_align) + '</th><th></th></tr></thead><tbody class="nuLvColRows">'
			+ colRows.map(function (c) {
				var lock = lockedOf[c.key];
				return '<tr draggable="true" data-key="' + esc(c.key) + '"><td class="nuLvGrip"><i class="bi bi-grip-vertical"></i></td>'
					+ '<td><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="' + p + 'c_' + esc(c.key) + '"' + (c.hidden ? '' : ' checked') + (lock ? ' disabled' : '') + '>'
					+ '<label class="form-check-label" for="' + p + 'c_' + esc(c.key) + '">' + esc(colLabel[c.key]) + (lock ? ' <i class="bi bi-lock small text-body-secondary" title="' + esc(T.lv_locked) + '"></i>' : '') + '</label></div></td>'
					+ '<td>' + sel(widthOpts, c.width, 'lvW') + '</td><td>' + sel(alignOpts, c.align, 'lvA') + '</td><td class="text-nowrap">' + moveBtns() + '</td></tr>';
			}).join('') + '</tbody></table></div></div>';
		// phone card
		var colOpts = D.columns.map(function (c) { return [c.key, c.label]; });
		var noneOpts = [['', T.lv_none]].concat(colOpts);
		html += '<div class="tab-pane fade" id="' + p + 'phone" role="tabpanel"><div class="row g-3"><div class="col-md-7">'
			+ (D.grid && d.display === 'grid' ? '' : '<label class="form-label small mb-1">' + esc(T.lv_phone_layout) + '</label>' + sel([['cards', T.lv_phone_cards], ['table', T.lv_phone_table]], d.phone || 'cards', 'lvPhoneLayout') + '<hr class="my-2">')
			+ '<label class="form-label small mb-1">' + esc(T.lv_card_title) + '</label>' + sel(colOpts, d.card.title, 'lvCardTitle')
			+ '<label class="form-label small mb-1 mt-2">' + esc(T.lv_card_badge) + '</label>' + sel(noneOpts, d.card.badge, 'lvCardBadge')
			+ '<label class="form-label small mb-1 mt-2">' + esc(T.lv_card_lines) + '</label>';
		for (var i = 0; i < 4; i++) html += '<div class="mb-1">' + sel(noneOpts, d.card.lines[i] || '', 'lvCardLine') + '</div>';
		html += '</div><div class="col-md-5"><div class="small text-body-secondary mb-1">' + esc(T.lv_card_preview) + '</div><div class="nuLvCard nuLvCardDemo"></div></div></div></div>';
		// filters
		html += '<div class="tab-pane fade" id="' + p + 'filters" role="tabpanel"><table class="table table-sm align-middle nuLvEdTable"><thead><tr><th></th><th>' + esc(T.lv_filter_shown) + '</th><th>' + esc(T.lv_filter_default) + '</th><th></th></tr></thead><tbody class="nuLvFilRows">';
		var fOrder = d.filters.shown.concat(D.filters.map(function (f) { return f.key; }).filter(function (k) { return d.filters.shown.indexOf(k) === -1; }));
		fOrder.forEach(function (k) {
			var f = D.filters.filter(function (x) { return x.key === k; })[0];
			if (!f) return;
			var cur = d.filters.values[k] !== undefined ? d.filters.values[k] : (f.input ? '' : f.options[0].v);
			html += '<tr draggable="true" data-key="' + esc(k) + '"><td class="nuLvGrip"><i class="bi bi-grip-vertical"></i></td><td><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="' + p + 'f_' + esc(k) + '"' + (d.filters.shown.indexOf(k) !== -1 ? ' checked' : '') + '>'
				+ '<label class="form-check-label" for="' + p + 'f_' + esc(k) + '">' + esc(f.label) + '</label></div></td><td>' + (f.input ? '<input type="' + (f.input === 'date' ? 'date' : 'text') + '" class="form-control form-control-sm lvFDef" value="' + esc(cur) + '" aria-label="' + esc(T.lv_filter_default) + '">'
				: sel(f.options.map(function (o) { return [o.v, o.l]; }), cur, 'lvFDef')) + '</td><td class="text-nowrap">' + moveBtns() + '</td></tr>';
		});
		html += '</tbody></table><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="' + p + 'open"' + (d.open ? ' checked' : '') + '><label class="form-check-label" for="' + p + 'open">' + esc(T.lv_filters_open) + '</label></div></div>';
		// general
		var sortOpts = D.columns.filter(function (c) { return c.sort; }).map(function (c) { return [c.key, c.label]; });
		html += '<div class="tab-pane fade" id="' + p + 'general" role="tabpanel"><div class="row g-3">'
			+ '<div class="col-md-5"><label class="form-label">' + esc(T.lv_sort) + '</label>' + sel(sortOpts, d.sort[0], 'lvSortKey') + '</div>'
			+ '<div class="col-md-4 d-flex align-items-end">' + sel([['asc', T.lv_sort_asc], ['desc', T.lv_sort_desc]], d.sort[1], 'lvSortDir') + '</div>'
			+ '<div class="col-md-3"><label class="form-label">' + esc(T.lv_per) + '</label>' + sel(D.per.map(function (n) { return [n, n]; }), d.per, 'lvPer') + '</div>'
			+ (D.grid ? '<div class="col-md-5"><label class="form-label">' + esc(T.lv_display) + '</label>' + sel([['table', T.lv_display_table], ['grid', T.lv_display_grid]], d.display || 'table', 'lvDisplay') + '</div>' : '');
		if (D.manage) {
			html += '<div class="col-12"><label class="form-label">' + esc(T.lv_scope) + '</label><div>'
				+ '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="' + p + 'scope" id="' + p + 'su" value="user"' + (scope === 'user' ? ' checked' : '') + '><label class="form-check-label" for="' + p + 'su">' + esc(T.lv_scope_user) + '</label></div>'
				+ '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="' + p + 'scope" id="' + p + 'ss" value="shared"' + (scope === 'shared' ? ' checked' : '') + '><label class="form-check-label" for="' + p + 'ss">' + esc(T.lv_scope_shared) + '</label></div></div></div>'
				+ '<div class="col-12 lvLevelsBox' + (scope === 'shared' ? '' : ' d-none') + '"><label class="form-label">' + esc(T.lv_levels) + '</label><div class="small text-body-secondary mb-1">' + esc(T.lv_levels_all) + '</div><div class="nuLvLevels">'
				+ D.levels.map(function (l) { return '<div class="form-check form-check-inline"><input class="form-check-input lvLevel" type="checkbox" id="' + p + 'l_' + esc(l.id) + '" value="' + esc(l.id) + '"' + (levels.indexOf(l.id) !== -1 ? ' checked' : '') + '><label class="form-check-label" for="' + p + 'l_' + esc(l.id) + '">' + esc(l.code) + '</label></div>'; }).join('')
				+ '</div></div>';
		}
		html += '</div></div></div><div class="alert alert-danger d-none mt-3 mb-0 lvErr" role="alert"></div>';

		var footer = (!isNew && V.editable ? '<button type="button" class="btn btn-outline-danger me-auto" data-lv-act="delete"><i class="bi bi-trash me-1"></i>' + esc(T.lv_delete) + '</button>' : '')
			+ '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' + esc(T.cancel) + '</button>'
			+ (!isNew ? '<button type="button" class="btn btn-outline-primary" data-lv-act="savenew">' + esc(T.lv_save_new) + '</button>' : '')
			+ '<button type="button" class="btn btn-primary" data-lv-act="save"><i class="bi bi-check2 me-1"></i>' + esc(T.lv_save) + '</button>';
		var el = modal(isNew ? T.lv_new_title : T.lv_edit_title, html, footer, 'modal-lg');
		sortable(el.querySelector('.nuLvColRows'));
		sortable(el.querySelector('.nuLvFilRows'));
		var $ = function (s) { return el.querySelector(s); };
		var $$ = function (s) { return Array.prototype.slice.call(el.querySelectorAll(s)); };

		function preview() {
			var t = $('.lvCardTitle').value, b = $('.lvCardBadge').value;
			var lines = $$('.lvCardLine').map(function (s) { return s.value; }).filter(Boolean);
			$('.nuLvCardDemo').innerHTML = '<span class="nuLvCardHead"><span class="nuLvCardTitle">' + esc(colLabel[t]) + '</span>' + (b ? '<span class="nuLvCardBadge"><span class="badge bg-secondary-subtle text-secondary-emphasis border">' + esc(colLabel[b]) + '</span></span>' : '') + '</span>'
				+ lines.map(function (k) { return '<span class="nuLvCardLine"><span class="nuLvCardLabel">' + esc(colLabel[k]) + '</span><span class="nuLvCardVal">…</span></span>'; }).join('');
		}
		el.addEventListener('change', function (e) {
			if (e.target.closest('#' + p + 'phone')) preview();
			if (e.target.name === p + 'scope') $('.lvLevelsBox').classList.toggle('d-none', e.target.value !== 'shared');
		});
		preview();

		function collect() {
			var def = {
				columns: $$('.nuLvColRows tr').filter(function (tr) { return tr.querySelector('input').checked; })
					.map(function (tr) { return {key: tr.dataset.key, width: tr.querySelector('.lvW').value, align: tr.querySelector('.lvA').value}; }),
				card: {title: $('.lvCardTitle').value, badge: $('.lvCardBadge').value, lines: $$('.lvCardLine').map(function (s) { return s.value; }).filter(Boolean)},
				sort: [$('.lvSortKey') ? $('.lvSortKey').value : d.sort[0], $('.lvSortDir').value],
				filters: {shown: [], values: {}},
				per: +$('.lvPer').value, open: $('#' + p + 'open').checked, display: $('.lvDisplay') ? $('.lvDisplay').value : 'table',
				phone: $('.lvPhoneLayout') ? $('.lvPhoneLayout').value : 'cards'
			};
			$$('.nuLvFilRows tr').forEach(function (tr) {
				var k = tr.dataset.key, f = D.filters.filter(function (x) { return x.key === k; })[0];
				if (tr.querySelector('input').checked) def.filters.shown.push(k);
				var v = tr.querySelector('.lvFDef').value;
				if (v !== (f.input ? '' : f.options[0].v)) def.filters.values[k] = v;
			});
			var sc = el.querySelector('input[name="' + p + 'scope"]:checked');
			return {name: $('.lvName').value.trim(), scope: sc ? sc.value : 'user',
				levels: $$('.lvLevel').filter(function (c) { return c.checked; }).map(function (c) { return c.value; }), def: def};
		}
		function fail(msg) { var a = $('.lvErr'); a.textContent = msg; a.classList.remove('d-none'); }
		el.addEventListener('click', function (e) {
			var b = e.target.closest('[data-lv-act]');
			if (!b) return;
			var act = b.getAttribute('data-lv-act');
			if (act === 'delete') {
				if (!window.confirm(T.lv_delete_confirm)) return;
				post({action: 'delete', id: V.id}).then(function (j) { location.href = j.url; }).catch(function (er) { fail(er.message); });
				return;
			}
			var v = collect();
			if (!v.name) { fail(T.lv_err_name); return; }
			b.disabled = true;
			post({action: 'save', id: act === 'save' && !isNew ? V.id : '', view: JSON.stringify(v)})
				.then(function (j) { location.href = j.url; })
				.catch(function (er) { b.disabled = false; fail(er.message); });
		});
	}

	// ------------------------------------------------------------------ import
	function importDialog() {
		var p = 'nuLvI' + (++uid);
		var el = modal(T.lv_import_title, '<p class="small text-body-secondary">' + esc(T.lv_import_hint) + '</p><input type="file" class="form-control" id="' + p + '" accept=".json,application/json">'
			+ '<div class="alert alert-danger d-none mt-3 mb-0 lvErr" role="alert"></div>',
			'<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' + esc(T.cancel) + '</button><button type="button" class="btn btn-primary" data-lv-go="1"><i class="bi bi-upload me-1"></i>' + esc(T.lv_import_go) + '</button>', '');
		el.querySelector('[data-lv-go]').addEventListener('click', function () {
			var f = el.querySelector('#' + p).files[0], btn = this;
			if (!f) return;
			btn.disabled = true;
			post({action: 'import'}, f).then(function (j) { location.href = j.url; }).catch(function (er) {
				btn.disabled = false;
				var a = el.querySelector('.lvErr'); a.textContent = er.message; a.classList.remove('d-none');
			});
		});
	}

	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-lv-edit]');
		if (b) { editor(b.getAttribute('data-lv-edit')); return; }
		if (e.target.closest('[data-lv-import]')) importDialog();
	});
})();

/* Phones (0.8.471): the ▦ / ☰ button switches this list between cards and table. The choice is kept on the device per list
   (localStorage nunext-lv-phone-<route>, read early by nutheme.js → <html data-lv-phone>); choosing the view's own layout removes it,
   so the list follows its view again. */
(function () {
	'use strict';
	var box = document.querySelector('.nuLv[data-lv-phone]'), btn = box && box.querySelector('[data-lv-phone-toggle]');
	if (!btn) return;
	var route = (window.location.search.match(/[?&]p=([\w-]+)/) || [])[1] || '', root = document.documentElement;
	function current() { return root.getAttribute('data-lv-phone') || box.getAttribute('data-lv-phone') || 'cards'; }
	function mark() { btn.setAttribute('aria-pressed', current() === 'table' ? 'true' : 'false'); }
	btn.addEventListener('click', function () {
		var next = current() === 'table' ? 'cards' : 'table';
		try {
			if (next === box.getAttribute('data-lv-phone')) localStorage.removeItem('nunext-lv-phone-' + route);
			else localStorage.setItem('nunext-lv-phone-' + route, next);
		} catch (e) { /* storage blocked: only for this page */ }
		if (next === box.getAttribute('data-lv-phone')) root.removeAttribute('data-lv-phone'); else root.setAttribute('data-lv-phone', next);
		mark();
	});
	mark();
})();

// long cells fold to three lines with "more" / "less" (0.8.690): <div class="nuLvClamp" data-more data-less> – the log's details
(function () {
	'use strict';
	var boxes = document.querySelectorAll('.nuLvClamp');
	if (!boxes.length) return;
	function setup(box) {
		if (box.getAttribute('data-clamp-done')) return;
		box.classList.add('nuLvClamped');
		if (box.scrollHeight <= box.clientHeight + 2) { box.classList.remove('nuLvClamped'); return; }   // short: nothing to fold
		box.setAttribute('data-clamp-done', '1');
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'btn btn-link btn-sm p-0 nuLvMore';
		b.textContent = box.getAttribute('data-more') || '…';
		b.setAttribute('aria-expanded', 'false');
		b.addEventListener('click', function (e) {
			e.preventDefault(); e.stopPropagation();          // the row opens the record otherwise
			var open = box.classList.toggle('nuLvClamped') === false;
			b.textContent = open ? (box.getAttribute('data-less') || '…') : (box.getAttribute('data-more') || '…');
			b.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
		box.insertAdjacentElement('afterend', b);
	}
	boxes.forEach(setup);
})();
