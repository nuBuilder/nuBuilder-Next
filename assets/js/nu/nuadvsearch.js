/* Advanced search of form browse lists (0.8.719, core/form/nuadvsearch.php): the ▾ next to the search opens #nuAdvPanel – conditions on the
   list's columns (field, operator, value), all / any, Apply (?adv=…), Clear, "My searches" (?p=advsearch). nuNextAdvancedSearch() reads or
   sets the search from form code. Data: window.nuNext.adv {cols, cur, ops, saved, url, form, canSave, texts}. */
(function () {
	'use strict';
	var N = window.nuNext || {}, A = N.adv;
	var panel = document.getElementById('nuAdvPanel');
	if (!A) return;
	var T = A.texts || {};
	var cols = A.cols || [];
	function t(k) { return T[k] || k; }
	function col(i) { for (var k = 0; k < cols.length; k++) if (cols[k].i === +i) return cols[k]; return null; }
	function need(c, op) { return c && A.ops[c.y] ? A.ops[c.y][op] : undefined; }
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined && text !== null) e.textContent = text; return e; }
	function opt(sel, v, label, on) { var o = new Option(label, v); if (on) o.selected = true; sel.appendChild(o); return o; }

	/** The URL with the given search (null = none), q = the search field, back to page 1. */
	function go(adv) {
		var u = new URL(window.location.href), f = document.getElementById('nuSearchField');
		if (adv && adv.c && adv.c.length) u.searchParams.set('adv', JSON.stringify(adv)); else u.searchParams.delete('adv');
		if (f) { if (f.value) u.searchParams.set('q', f.value); else u.searchParams.delete('q'); }
		u.searchParams.delete('pg');
		window.location.href = u.toString();
	}

	var state = {m: (A.cur && A.cur.m) || 'all', rows: ((A.cur && A.cur.c) || []).map(function (c) { return {i: c[0], o: c[1], v: c[2], v2: c[3]}; })};
	var rowsBox = null, saveName = null;

	function valueInputs(r, c, n) {
		var box = el('div', 'd-flex gap-1 flex-grow-1 nuAdvVals');
		if (n === '' || n === undefined) { box.classList.add('d-none'); return box; }
		var mk = function (val, key) {
			var inp = el('input', 'form-control form-control-sm nuAdvVal');
			inp.type = c.y === 'date' ? 'date' : 'text';
			if (c.y === 'number') inp.inputMode = 'decimal';
			inp.value = val || ''; inp.setAttribute('aria-label', t('as_value'));
			inp.addEventListener('input', function () { r[key] = inp.value; });
			inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
			return inp;
		};
		if (n === 'list') {
			if (c.v && c.v.length) {
				var sel = el('select', 'form-select form-select-sm nuAdvVal'); sel.multiple = true; sel.size = Math.min(5, c.v.length);
				sel.setAttribute('aria-label', t('as_value'));
				var cur = Array.isArray(r.v) ? r.v : String(r.v || '').split(/\s*;\s*/);
				c.v.forEach(function (x) { opt(sel, x[0], x[1], cur.indexOf(x[0]) >= 0); });
				sel.addEventListener('change', function () { r.v = Array.prototype.filter.call(sel.options, function (o) { return o.selected; }).map(function (o) { return o.value; }); });
				box.appendChild(sel);
			} else {
				var li = mk(Array.isArray(r.v) ? r.v.join('; ') : r.v, 'v'); li.placeholder = t('as_list_hint'); li.title = t('as_list_hint'); box.appendChild(li);
			}
			return box;
		}
		box.appendChild(mk(r.v, 'v'));
		if (n === '2') { box.appendChild(el('span', 'small align-self-center text-body-secondary', t('as_and'))); box.appendChild(mk(r.v2, 'v2')); }
		return box;
	}

	function drawRows() {
		rowsBox.textContent = '';
		state.rows.forEach(function (r, k) {
			var c = col(r.i) || cols[0];
			r.i = c.i;
			if (need(c, r.o) === undefined) r.o = Object.keys(A.ops[c.y])[0];
			var row = el('div', 'd-flex flex-wrap gap-1 align-items-start mb-2 nuAdvRow');
			var fs = el('select', 'form-select form-select-sm nuAdvField'); fs.setAttribute('aria-label', t('as_field'));
			cols.forEach(function (x) { opt(fs, x.i, x.t, x.i === c.i); });
			fs.addEventListener('change', function () { r.i = +fs.value; r.v = ''; r.v2 = ''; drawRows(); });
			var os = el('select', 'form-select form-select-sm nuAdvOp'); os.setAttribute('aria-label', t('as_op'));
			Object.keys(A.ops[c.y]).forEach(function (o) { opt(os, o, t('as_op_' + o), o === r.o); });
			os.addEventListener('change', function () {
				var was = need(c, r.o); r.o = os.value;
				if (need(c, r.o) !== was) { r.v = ''; r.v2 = ''; }
				drawRows();
			});
			var del = el('button', 'btn btn-sm btn-outline-secondary nuAdvDel'); del.type = 'button'; del.title = t('as_remove'); del.setAttribute('aria-label', t('as_remove'));
			del.appendChild(el('i', 'bi bi-x-lg'));
			del.addEventListener('click', function () { state.rows.splice(k, 1); if (!state.rows.length) addRow(); else drawRows(); });
			row.appendChild(fs); row.appendChild(os); row.appendChild(valueInputs(r, c, need(c, r.o))); row.appendChild(del);
			rowsBox.appendChild(row);
		});
	}
	function addRow() {
		var c = cols[0];
		state.rows.push({i: c.i, o: Object.keys(A.ops[c.y])[0], v: '', v2: ''});
		drawRows();
		var last = rowsBox.lastElementChild, f = last && last.querySelector('.nuAdvVal');
		if (f) f.focus();
	}
	function collect() {
		return {m: state.m, c: state.rows.filter(function (r) {
			var n = need(col(r.i), r.o);
			if (n === '' ) return true;
			if (n === 'list') return Array.isArray(r.v) ? r.v.length > 0 : String(r.v || '').trim() !== '';
			return String(r.v || '').trim() !== '' && (n !== '2' || String(r.v2 || '').trim() !== '');
		}).map(function (r) { return [r.i, r.o, r.v, r.v2 || '']; })};
	}
	function apply() { go(collect()); }

	function post(body) {
		return fetch(A.url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': N.csrf, 'Accept': 'application/json'},
			body: JSON.stringify(Object.assign({f: A.form}, body))}).then(function (r) { return r.json(); });
	}
	function drawSaved(box) {
		box.textContent = '';
		box.appendChild(el('div', 'small fw-semibold text-body-secondary mb-1', t('as_saved')));
		if (!A.saved.length) box.appendChild(el('div', 'small text-body-secondary mb-2', t('as_none_saved')));
		A.saved.forEach(function (s) {
			var row = el('div', 'd-flex align-items-center gap-1 nuAdvSaved');
			var a = el('a', 'small text-truncate flex-grow-1 nuAdvSavedUse', s.name); a.href = '#';
			a.addEventListener('click', function (e) { e.preventDefault(); try { go(JSON.parse(s.adv)); } catch (x) { /* broken row */ } });
			row.appendChild(a);
			if (A.canSave) {
				var d = el('button', 'btn btn-sm btn-link text-danger p-0 nuAdvSavedDel'); d.type = 'button'; d.title = t('as_delete'); d.setAttribute('aria-label', t('as_delete') + ': ' + s.name);
				d.appendChild(el('i', 'bi bi-trash'));
				d.addEventListener('click', function () { post({action: 'delete', id: s.id}).then(function (j) { A.saved = j.saved || []; drawSaved(box); }); });
				row.appendChild(d);
			}
			box.appendChild(row);
		});
		if (!A.canSave) return;
		var form = el('div', 'input-group input-group-sm mt-2');
		saveName = el('input', 'form-control nuAdvSaveName'); saveName.placeholder = t('as_save_name'); saveName.maxLength = 100; saveName.setAttribute('aria-label', t('as_save_name'));
		var b = el('button', 'btn btn-outline-secondary nuAdvSaveBtn', t('as_save_btn')); b.type = 'button';
		var msg = el('div', 'small text-danger w-100 mt-1'); msg.hidden = true;
		b.addEventListener('click', function () {
			var adv = collect();
			if (!saveName.value.trim()) { msg.textContent = t('as_err_name'); msg.hidden = false; saveName.focus(); return; }
			post({action: 'save', name: saveName.value, adv: JSON.stringify(adv)}).then(function (j) {
				if (!j.ok) { msg.textContent = j.error || ''; msg.hidden = false; return; }
				A.saved = j.saved || []; drawSaved(box);
			});
		});
		saveName.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); b.click(); } });
		form.appendChild(saveName); form.appendChild(b);
		box.appendChild(form); box.appendChild(msg);
	}

	function build() {
		if (!panel || panel.childElementCount) return;
		var head = el('div', 'd-flex flex-wrap align-items-center gap-2 mb-2');
		head.appendChild(el('div', 'fw-semibold me-auto', t('as_title')));
		var mg = el('div', 'btn-group btn-group-sm nuAdvMatch'); mg.setAttribute('role', 'group'); mg.setAttribute('aria-label', t('as_match'));
		[['all', t('as_all')], ['any', t('as_any')]].forEach(function (x) {
			var id = 'nuAdvM_' + x[0], r = el('input', 'btn-check'); r.type = 'radio'; r.name = 'nuAdvMatch'; r.id = id; r.value = x[0]; r.checked = state.m === x[0];
			r.addEventListener('change', function () { if (r.checked) state.m = x[0]; });
			var l = el('label', 'btn btn-outline-secondary', x[1]); l.htmlFor = id;
			mg.appendChild(r); mg.appendChild(l);
		});
		head.appendChild(el('span', 'small text-body-secondary', t('as_match'))); head.appendChild(mg);
		panel.appendChild(head);
		rowsBox = el('div', 'nuAdvRows'); panel.appendChild(rowsBox);
		var add = el('button', 'btn btn-sm btn-link p-0 mb-2 nuAdvAdd'); add.type = 'button';
		add.appendChild(el('i', 'bi bi-plus-lg me-1')); add.appendChild(document.createTextNode(t('as_add')));
		add.addEventListener('click', addRow);
		panel.appendChild(add);
		var foot = el('div', 'd-flex justify-content-end gap-2 border-top pt-2');
		var clr = el('button', 'btn btn-sm btn-outline-secondary nuAdvClear', t('as_clear')); clr.type = 'button'; clr.addEventListener('click', function () { go(null); });
		var ap = el('button', 'btn btn-sm btn-primary nuAdvApply', t('as_apply')); ap.type = 'button'; ap.addEventListener('click', apply);
		foot.appendChild(clr); foot.appendChild(ap); panel.appendChild(foot);
		var saved = el('div', 'border-top pt-2 mt-2 nuAdvSavedBox'); panel.appendChild(saved);
		drawSaved(saved);
		if (!state.rows.length) addRow(); else drawRows();
	}
	if (panel && cols.length) {
		var btn = document.getElementById('nuAdvButton');
		if (btn) btn.addEventListener('show.bs.dropdown', build);
		panel.addEventListener('click', function (e) { e.stopPropagation(); });
	} else if (panel) {
		var b2 = document.getElementById('nuAdvButton'); if (b2) b2.hidden = true;
	}

	/**
	 * nuNextAdvancedSearch() → {mode, conditions: [{column, title, op, value, value2}]} (the search of the list);
	 * nuNextAdvancedSearch(conditions[, mode]) → sets it and reloads the list: conditions = [{column: index | title, op, value, value2}], [] = none.
	 */
	window.nuNextAdvSearch = function (conditions, mode) {   // nuNextAdvancedSearch() of nuruntime.js calls this
		if (conditions === undefined) {
			return {mode: state.m, conditions: ((A.cur && A.cur.c) || []).map(function (c) { var x = col(c[0]); return {column: c[0], title: x ? x.t : '', op: c[1], value: c[2], value2: c[3]}; })};
		}
		var list = (Array.isArray(conditions) ? conditions : [conditions]).map(function (c) {
			var x = typeof c.column === 'number' ? col(c.column) : cols.filter(function (y) { return y.t === String(c.column); })[0];
			return x ? [x.i, String(c.op || 'contains'), c.value === undefined ? '' : c.value, c.value2 === undefined ? '' : String(c.value2)] : null;
		}).filter(Boolean);
		go({m: mode === 'any' ? 'any' : 'all', c: list});
		return true;
	};
})();
