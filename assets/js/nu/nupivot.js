/* global nuBs */
/*
 * Pivot of a form list (0.8.1151): Export ▾ → Pivot… (#nuPivotOpen, data-nu-pivot = form id + the list's search state, data-nu-pivot-texts).
 * Fields are dragged (or added with +) into Filters / Columns / Rows / Values; the server works the numbers out (?p=pivot, core/form/nupivot.php).
 * Table with totals / subtotals, % of row / column / total, heat map, sort + top N, chart (ApexCharts, loaded on first use), CSV / Excel,
 * a click on a number lists its records. Saved pivots per user ("My pivots"). The last pivot of a form is remembered (localStorage).
 */
(function () {
	'use strict';
	var me = document.currentScript;
	var APEX = me ? me.getAttribute('data-apex') : '';
	var T = {}, S = null, F = [], BY = {}, R = null, timer = null, seq = 0, chart = null;
	var VIEW = {show: 'val', sort: 'key', top: 0, heat: false, mode: 'table', ctype: 'bar'};
	var PARTS = ['', 'year', 'quarter', 'month', 'monthnum', 'week', 'weekday', 'day'];
	var FNS = ['count', 'sum', 'avg', 'min', 'max', 'countd'];
	var lang = document.documentElement.lang || undefined;
	var nf = new Intl.NumberFormat(lang, {maximumFractionDigits: 2});
	var pf = new Intl.NumberFormat(lang, {style: 'percent', maximumFractionDigits: 1});
	var NF = [];   // per value: 2 decimals when any of its numbers has a fraction, else none (one look per column)
	function setFormats() {
		NF = (R.spec.vals || []).map(function (v, j) {
			var frac = v.fn === 'avg';
			var see = function (a) { if (a && a[j] !== null && a[j] !== undefined && Math.round(a[j]) !== a[j]) frac = true; };
			Object.keys(R.cells).forEach(function (k) { see(R.cells[k]); });
			Object.keys(R.rowTotals || {}).forEach(function (k) { see(R.rowTotals[k]); });
			return new Intl.NumberFormat(lang, frac ? {minimumFractionDigits: 2, maximumFractionDigits: 2} : {maximumFractionDigits: 0});
		});
	}
	function fmt(v, j) { return (NF[j] || nf).format(v); }

	function t(k) { return T[k] || k; }
	function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt !== undefined && txt !== null) e.textContent = txt; return e; }
	function store(k, v) { try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* no storage */ } return null; }

	function post(action, extra) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('f', S.f);
		Object.keys(S).forEach(function (k) {
			if (k === 'f' || k === 'ps') return;
			var v = S[k];
			if (v && typeof v === 'object') Object.keys(v).forEach(function (i) { fd.append(k + '[' + i + ']', v[i]); });
			else if (v !== null && v !== undefined) fd.append(k, v);
		});
		Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
		var url = 'index.php?p=pivot&f=' + encodeURIComponent(S.f) + (S.ps ? '&ps=' + encodeURIComponent(S.ps) : '');
		return fetch(url, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-CSRF-Token': (window.nuNext || {}).csrf || '', 'Accept': 'application/json'}})
			.then(function (r) { return r.json(); });
	}

	// ------------------------------------------------------------------ labels
	function keyLabel(k, g) {
		if (k === '' || k === null || k === undefined) return t('pv_empty_val');
		var d;
		switch (g) {
			case 'month': d = new Date(k + '-01T00:00:00'); return isNaN(d) ? k : d.toLocaleDateString(lang, {year: 'numeric', month: 'short'});
			case 'monthnum': d = new Date(2001, parseInt(k, 10) - 1, 1); return isNaN(d) ? k : d.toLocaleDateString(lang, {month: 'long'});
			case 'weekday': d = new Date(2001, 0, parseInt(k, 10)); return isNaN(d) ? k : d.toLocaleDateString(lang, {weekday: 'long'});   // 1 Jan 2001 = Monday
			case 'day': d = new Date(k + 'T00:00:00'); return isNaN(d) ? k : d.toLocaleDateString(lang);
		}
		return String(k);
	}
	function keyText(key, dims) { return key.map(function (k, i) { return keyLabel(k, (dims[i] || {}).g); }).join(' · '); }
	function fieldTitle(c) { return BY[c] ? BY[c].title : '#' + c; }
	function valTitle(v) { return v.fn === 'count' ? t('pv_records') : t('pv_fn_' + v.fn) + ' ' + fieldTitle(v.c); }

	// ------------------------------------------------------------------ dialog
	var D, Z = {};
	function build() {
		D = el('div', 'modal fade nuPvDlg');
		D.id = 'nuPvDlg'; D.tabIndex = -1; D.setAttribute('aria-labelledby', 'nuPvTitle');
		D.innerHTML = '<div class="modal-dialog modal-xl modal-fullscreen-lg-down modal-dialog-scrollable"><div class="modal-content">'
			+ '<div class="modal-header py-2"><h2 class="modal-title fs-5" id="nuPvTitle"></h2><span class="small text-body-secondary ms-3 d-none d-md-inline" id="nuPvNote"></span>'
			+ '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
			+ '<div class="modal-body p-0"><div class="nuPvWrap"><aside class="nuPvSide" id="nuPvSide"></aside><section class="nuPvMain">'
			+ '<div class="nuPvBar" id="nuPvBar"></div><div class="nuPvOut" id="nuPvOut"></div><div class="nuPvDrill" id="nuPvDrill" hidden></div></section></div></div></div></div>';
		document.body.appendChild(D);
		D.querySelector('#nuPvTitle').textContent = t('pv_title');
		D.querySelector('#nuPvNote').textContent = t('pv_note');
		D.querySelector('.btn-close').setAttribute('aria-label', t('close'));
		D.addEventListener('hidden.bs.modal', function () { if (chart) { try { chart.destroy(); } catch (e) { /* gone */ } chart = null; } });
		buildSide(); buildBar();
	}

	function buildSide() {
		var side = D.querySelector('#nuPvSide');
		side.innerHTML = '';
		side.appendChild(el('div', 'nuPvHead', t('pv_fields')));
		var list = el('div', 'nuPvFields');
		var cnt = chip({c: -1, title: t('pv_records'), type: 'count'}, true);
		list.appendChild(cnt);
		F.forEach(function (f) { list.appendChild(chip(f, true)); });
		side.appendChild(list);
		[['flt', 'pv_filters', 'bi-funnel'], ['cols', 'pv_cols', 'bi-layout-three-columns'], ['rows', 'pv_rows', 'bi-list'], ['vals', 'pv_vals', 'bi-123']].forEach(function (z) {
			var box = el('div', 'nuPvZone'); box.setAttribute('data-zone', z[0]);
			var h = el('div', 'nuPvHead d-flex align-items-center');
			h.innerHTML = '<i class="bi ' + z[2] + ' me-1" aria-hidden="true"></i>';
			h.appendChild(el('span', 'flex-grow-1', t(z[1])));
			var add = el('div', 'dropdown');
			add.innerHTML = '<button class="btn btn-sm btn-link p-0 nuPvAdd" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-plus-lg"></i></button><ul class="dropdown-menu dropdown-menu-end shadow-sm nuPvAddMenu"></ul>';
			add.querySelector('button').title = t('pv_add'); add.querySelector('button').setAttribute('aria-label', t('pv_add') + ' – ' + t(z[1]));
			var menu = add.querySelector('ul');
			var opts = (z[0] === 'vals' ? [{c: -1, title: t('pv_records')}] : []).concat(F);
			opts.forEach(function (f) {
				var li = el('li'); var b = el('button', 'dropdown-item', f.title); b.type = 'button';
				b.addEventListener('click', function () { drop(z[0], f.c); });
				li.appendChild(b); menu.appendChild(li);
			});
			h.appendChild(add);
			box.appendChild(h);
			var body = el('div', 'nuPvZoneBody'); body.setAttribute('data-empty', t('pv_drop'));
			box.appendChild(body);
			box.addEventListener('dragover', function (e) { e.preventDefault(); box.classList.add('nuPvOver'); });
			box.addEventListener('dragleave', function () { box.classList.remove('nuPvOver'); });
			box.addEventListener('drop', function (e) {
				e.preventDefault(); box.classList.remove('nuPvOver');
				var d; try { d = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (x) { return; }
				if (d.from) take(d.from, d.i);
				drop(z[0], d.c, d.item);
			});
			Z[z[0]] = body;
			side.appendChild(box);
		});
	}
	function chip(f, source) {
		var c = el('div', 'nuPvChip' + (source ? ' nuPvSrc' : ''));
		c.draggable = true;
		c.appendChild(el('i', 'bi ' + (f.type === 'date' ? 'bi-calendar3' : f.type === 'number' ? 'bi-hash' : f.type === 'count' ? 'bi-123' : 'bi-fonts') + ' me-1'));
		c.appendChild(el('span', '', f.title));
		c.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', JSON.stringify({c: f.c})); });
		return c;
	}
	function drop(zone, c, item) {
		var spec = S.spec;
		if (zone === 'vals') {
			var fn = c === -1 ? 'count' : (item && item.fn) || (BY[c] && BY[c].type === 'number' ? 'sum' : 'countd');
			spec.vals.push({c: c, fn: fn});
		} else {
			if (c === -1) return;
			var g = item && item.g !== undefined ? item.g : (BY[c] && BY[c].type === 'date' ? (zone === 'cols' ? 'year' : 'month') : '');
			var list = spec[zone];
			if (list.length >= 3) return;
			list.push(zone === 'flt' ? {c: c, g: g, v: (item && item.v) || []} : {c: c, g: g});
		}
		changed();
	}
	function take(zone, i) { var it = S.spec[zone].splice(i, 1)[0]; return it; }

	function renderZones() {
		['flt', 'cols', 'rows', 'vals'].forEach(function (z) {
			var body = Z[z]; body.innerHTML = '';
			S.spec[z].forEach(function (it, i) {
				var c = el('div', 'nuPvChip nuPvIn');
				c.draggable = true;
				c.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', JSON.stringify({c: it.c, from: z, i: i, item: it})); });
				var name = z === 'vals' ? (it.c === -1 ? t('pv_records') : fieldTitle(it.c)) : fieldTitle(it.c);
				c.appendChild(el('span', 'nuPvName', name));
				if (z === 'vals' && it.c !== -1) {
					var s = el('select', 'form-select form-select-sm');
					FNS.forEach(function (fn) {
						if (fn === 'count') return;
						if ((fn === 'sum' || fn === 'avg') && BY[it.c] && BY[it.c].type !== 'number') return;
						var o = el('option', '', t('pv_fn_' + fn)); o.value = fn; s.appendChild(o);
					});
					s.value = it.fn;
					s.addEventListener('change', function () { it.fn = s.value; changed(); });
					c.appendChild(s);
				} else if (z !== 'vals' && BY[it.c] && BY[it.c].type === 'date') {
					var g = el('select', 'form-select form-select-sm');
					PARTS.forEach(function (p) { var o = el('option', '', t('pv_g_' + (p || 'value'))); o.value = p; g.appendChild(o); });
					g.value = it.g;
					g.addEventListener('change', function () { it.g = g.value; if (z === 'flt') it.v = []; changed(); });
					c.appendChild(g);
				}
				if (z === 'flt') {
					var fb = el('button', 'btn btn-sm btn-outline-secondary nuPvFltBtn', it.v.length ? String(it.v.length) : t('pv_top_all'));
					fb.type = 'button'; fb.title = t('pv_filters');
					fb.addEventListener('click', function () { filterMenu(it, fb); });
					c.appendChild(fb);
				}
				var x = el('button', 'btn btn-sm btn-link p-0 nuPvX'); x.type = 'button'; x.innerHTML = '<i class="bi bi-x-lg"></i>';
				x.title = t('pv_remove'); x.setAttribute('aria-label', t('pv_remove') + ' ' + name);
				x.addEventListener('click', function () { S.spec[z].splice(i, 1); changed(); });
				c.appendChild(x);
				body.appendChild(c);
			});
		});
	}

	function filterMenu(it, btn) {
		var box = D.querySelector('.nuPvFltPop'); if (box) box.remove();
		box = el('div', 'nuPvFltPop shadow');
		box.appendChild(el('div', 'small text-body-secondary mb-1', t('pv_working')));
		btn.after(box);
		post('values', {c: it.c, g: it.g}).then(function (d) {
			box.innerHTML = '';
			var list = el('div', 'nuPvFltList');
			(d.values || []).forEach(function (v) {
				var lab = el('label', 'form-check small');
				var cb = el('input', 'form-check-input'); cb.type = 'checkbox'; cb.value = v; cb.checked = it.v.indexOf(v) >= 0;
				lab.appendChild(cb); lab.appendChild(el('span', 'form-check-label', keyLabel(v, it.g)));
				list.appendChild(lab);
			});
			box.appendChild(list);
			var row = el('div', 'd-flex gap-2 mt-2');
			var ok = el('button', 'btn btn-sm btn-primary', 'OK'); ok.type = 'button';
			var clr = el('button', 'btn btn-sm btn-outline-secondary', t('pv_clear')); clr.type = 'button';
			ok.addEventListener('click', function () { it.v = Array.prototype.filter.call(list.querySelectorAll('input:checked'), function () { return true; }).map(function (i) { return i.value; }); box.remove(); changed(); });
			clr.addEventListener('click', function () { it.v = []; box.remove(); changed(); });
			row.appendChild(ok); row.appendChild(clr); box.appendChild(row);
		});
	}

	// ------------------------------------------------------------------ toolbar
	function sel(id, opts, val, fn) {
		var s = el('select', 'form-select form-select-sm w-auto'); s.id = id;
		opts.forEach(function (o) { var x = el('option', '', o[1]); x.value = o[0]; s.appendChild(x); });
		s.value = val; s.addEventListener('change', function () { fn(s.value); });
		return s;
	}
	function buildBar() {
		var bar = D.querySelector('#nuPvBar'); bar.innerHTML = '';
		var mode = el('div', 'btn-group btn-group-sm nuSeg');
		[['table', 'pv_table', 'bi-table'], ['chart', 'pv_chart', 'bi-bar-chart']].forEach(function (m) {
			var b = el('button', 'btn btn-outline-secondary' + (VIEW.mode === m[0] ? ' active' : '')); b.type = 'button'; b.id = 'nuPvMode_' + m[0];
			b.innerHTML = '<i class="bi ' + m[2] + ' me-1"></i>'; b.appendChild(document.createTextNode(t(m[1])));
			b.addEventListener('click', function () { VIEW.mode = m[0]; buildBar(); render(); saveView(); });
			mode.appendChild(b);
		});
		bar.appendChild(mode);
		if (VIEW.mode === 'chart') bar.appendChild(sel('nuPvCtype', [['bar', t('pv_chart_bar')], ['stack', t('pv_chart_stack')], ['line', t('pv_chart_line')]], VIEW.ctype, function (v) { VIEW.ctype = v; render(); saveView(); }));
		else {
			bar.appendChild(lbl(t('pv_show'), sel('nuPvShow', [['val', t('pv_show_val')], ['row', t('pv_show_row')], ['col', t('pv_show_col')], ['all', t('pv_show_all')]], VIEW.show, function (v) { VIEW.show = v; render(); saveView(); })));
			var hm = el('div', 'form-check form-switch mb-0'); var hc = el('input', 'form-check-input'); hc.type = 'checkbox'; hc.id = 'nuPvHeat'; hc.checked = VIEW.heat;
			hc.addEventListener('change', function () { VIEW.heat = hc.checked; render(); saveView(); });
			var hl = el('label', 'form-check-label small', t('pv_heat')); hl.htmlFor = 'nuPvHeat'; hm.appendChild(hc); hm.appendChild(hl); bar.appendChild(hm);
		}
		bar.appendChild(lbl(t('pv_sort'), sel('nuPvSort', [['key', t('pv_sort_key')], ['desc', t('pv_sort_desc')], ['asc', t('pv_sort_asc')]], VIEW.sort, function (v) { VIEW.sort = v; render(); saveView(); })));
		bar.appendChild(lbl(t('pv_top'), sel('nuPvTop', [['0', t('pv_top_all')], ['5', '5'], ['10', '10'], ['20', '20'], ['50', '50']], String(VIEW.top), function (v) { VIEW.top = parseInt(v, 10) || 0; render(); saveView(); })));
		var sp = el('span', 'flex-grow-1'); bar.appendChild(sp);
		// My pivots
		var dd = el('div', 'dropdown');
		dd.innerHTML = '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="nuPvSavedBtn"><i class="bi bi-bookmark me-1"></i></button><div class="dropdown-menu dropdown-menu-end shadow-sm p-2 nuPvSavedMenu" id="nuPvSavedMenu"></div>';
		dd.querySelector('button').appendChild(document.createTextNode(t('pv_saved')));
		dd.addEventListener('show.bs.dropdown', savedMenu);
		bar.appendChild(dd);
		var ex = el('div', 'btn-group btn-group-sm');
		[['csv', 'exp_csv', 'bi-filetype-csv'], ['xlsx', 'exp_xlsx', 'bi-file-earmark-excel']].forEach(function (x) {
			var b = el('button', 'btn btn-outline-secondary'); b.type = 'button'; b.id = 'nuPvExp_' + x[0]; b.title = t(x[1]); b.setAttribute('aria-label', t(x[1]));
			b.innerHTML = '<i class="bi ' + x[2] + '"></i>';
			b.addEventListener('click', function () { exportFile(x[0]); });
			ex.appendChild(b);
		});
		bar.appendChild(ex);
		var clr = el('button', 'btn btn-sm btn-outline-secondary', t('pv_clear')); clr.type = 'button'; clr.id = 'nuPvClear';
		clr.addEventListener('click', function () { S.spec = {rows: [], cols: [], vals: [], flt: []}; changed(); });
		bar.appendChild(clr);
	}
	function lbl(text, ctl) { var w = el('label', 'd-flex align-items-center gap-1 small mb-0'); w.appendChild(el('span', 'text-body-secondary', text)); w.appendChild(ctl); return w; }

	function savedMenu() {
		var m = D.querySelector('#nuPvSavedMenu');
		m.innerHTML = '<div class="small text-body-secondary">' + t('pv_working') + '</div>';
		post('saved').then(function (d) { drawSaved(d.saved || []); });
	}
	function drawSaved(list) {
		var m = D.querySelector('#nuPvSavedMenu'); m.innerHTML = '';
		if (!list.length) m.appendChild(el('div', 'small text-body-secondary px-2 py-1', t('pv_none_saved')));
		list.forEach(function (s) {
			var row = el('div', 'd-flex align-items-center gap-1');
			var b = el('button', 'dropdown-item flex-grow-1 nuPvLoad', s.name); b.type = 'button';
			b.addEventListener('click', function () { if (s.spec && s.spec.spec) { S.spec = s.spec.spec; if (s.spec.view) VIEW = Object.assign(VIEW, s.spec.view); buildBar(); changed(); } });
			var x = el('button', 'btn btn-sm btn-link text-danger p-0'); x.type = 'button'; x.innerHTML = '<i class="bi bi-trash"></i>'; x.title = t('delete');
			x.addEventListener('click', function () { post('delete', {id: s.id}).then(function (d) { drawSaved(d.saved || []); }); });
			row.appendChild(b);
			if (s.shared) { var sh = el('span', 'text-body-secondary px-1'); sh.innerHTML = '<i class="bi bi-people"></i>'; sh.title = t('pv_shared'); row.appendChild(sh); }
			else row.appendChild(x);
			m.appendChild(row);
		});
		m.appendChild(el('hr', 'dropdown-divider'));
		var f = el('div', 'd-flex gap-1');
		var inp = el('input', 'form-control form-control-sm'); inp.placeholder = t('pv_save_name'); inp.id = 'nuPvSaveName'; inp.maxLength = 100;
		var sb = el('button', 'btn btn-sm btn-primary', t('save')); sb.type = 'button'; sb.id = 'nuPvSave';
		sb.addEventListener('click', function () {
			if (!inp.value.trim()) { inp.focus(); return; }
			post('save', {name: inp.value, spec: JSON.stringify({spec: S.spec, view: VIEW})}).then(function (d) { drawSaved(d.saved || []); });
		});
		f.appendChild(inp); f.appendChild(sb); m.appendChild(f);
	}

	// ------------------------------------------------------------------ run + render
	function saveView() { store('nunext-pivot-' + S.f, {spec: S.spec, view: VIEW}); }
	function changed() {
		renderZones(); saveView();
		clearTimeout(timer);
		timer = setTimeout(run, 200);
	}
	function run() {
		var out = D.querySelector('#nuPvOut');
		D.querySelector('#nuPvDrill').hidden = true;
		if (!S.spec.rows.length && !S.spec.cols.length) { R = null; out.innerHTML = ''; out.appendChild(el('div', 'nuPvStart', t('pv_start'))); return; }
		var my = ++seq;
		out.classList.add('nuPvBusy');
		post('run', {spec: JSON.stringify(S.spec)}).then(function (d) {
			if (my !== seq) return;
			out.classList.remove('nuPvBusy');
			if (!d.ok) { R = null; out.innerHTML = ''; out.appendChild(el('div', 'alert alert-warning m-3', d.error || t('pv_err_query'))); return; }
			R = d; setFormats(); render();
		}).catch(function () { out.classList.remove('nuPvBusy'); });
	}

	function cellOf(rk, ck) { return R.cells[JSON.stringify(rk) + '|' + JSON.stringify(ck)] || null; }
	function rowTot(rk) { return R.spec.cols.length ? (R.rowTotals[JSON.stringify(rk)] || null) : cellOf(rk, []); }
	function colTot(ck) { return R.spec.rows.length ? (R.colTotals[JSON.stringify(ck)] || null) : cellOf([], ck); }
	function show(v, j, rk, ck) {
		if (v === null || v === undefined) return '';
		if (VIEW.show === 'val') return fmt(v, j);
		var base = VIEW.show === 'row' ? (rowTot(rk) || [])[j] : VIEW.show === 'col' ? (colTot(ck) || [])[j] : (R.grand || [])[j];
		return base ? pf.format(v / base) : '';
	}
	function orderedRows() {
		var rows = R.rows.slice();
		if (VIEW.sort !== 'key' && R.spec.rows.length === 1) {
			var k = function (r) { var x = rowTot(r); return x && x[0] !== null ? x[0] : 0; };
			rows.sort(function (a, b) { return VIEW.sort === 'desc' ? k(b) - k(a) : k(a) - k(b); });
		}
		if (VIEW.top > 0 && R.spec.rows.length <= 1) rows = rows.slice(0, VIEW.top);
		return rows;
	}

	function render() {
		var out = D.querySelector('#nuPvOut');
		if (!R) return;
		if (chart) { try { chart.destroy(); } catch (e) { /* gone */ } chart = null; }
		out.innerHTML = '';
		if (VIEW.mode === 'chart') return renderChart(out);
		var sp = R.spec, nv = sp.vals.length, rows = orderedRows(), cols = R.cols, nr = sp.rows.length;
		var wrap = el('div', 'table-responsive nuPvTableWrap');
		var tb = el('table', 'table table-sm nu-table nuPvTable'); tb.id = 'nuPvTable';
		var th = el('thead'), tr = el('tr');
		sp.rows.forEach(function (d) { tr.appendChild(el('th', 'nuPvRowHead', fieldTitle(d.c) + (d.g ? ' (' + t('pv_g_' + d.g) + ')' : ''))); });
		if (!nr) tr.appendChild(el('th', 'nuPvRowHead', ''));
		var colHead = function (ck) {
			sp.vals.forEach(function (v) {
				var h = el('th', 'text-end', (sp.cols.length ? keyText(ck, sp.cols) : '') + (nv > 1 || !sp.cols.length ? (sp.cols.length ? ' · ' : '') + valTitle(v) : ''));
				tr.appendChild(h);
			});
		};
		cols.forEach(colHead);
		if (sp.cols.length && nr) sp.vals.forEach(function (v) { tr.appendChild(el('th', 'text-end nuPvTot', t('pv_total') + (nv > 1 ? ' · ' + valTitle(v) : ''))); });
		th.appendChild(tr); tb.appendChild(th);
		var max = []; if (VIEW.heat) cols.forEach(function (ck) { rows.forEach(function (rk) { var c = cellOf(rk, ck) || []; c.forEach(function (x, j) { if (x !== null && (max[j] === undefined || Math.abs(x) > max[j])) max[j] = Math.abs(x); }); }); });
		var body = el('tbody'), prev = [];
		var addSub = function (k, pre) {
			var sub = R.sub[JSON.stringify(pre)]; if (!sub) return;
			var r = el('tr', 'nuPvSub');
			var lab = el('td', 'nuPvRowHead', t('pv_total') + ' ' + keyText(pre, sp.rows)); lab.colSpan = nr; r.appendChild(lab);
			cols.forEach(function (ck) { (sub[JSON.stringify(ck)] || new Array(nv).fill(null)).forEach(function (x, j) { r.appendChild(el('td', 'text-end', x === null ? '' : fmt(x, j))); }); });
			if (sp.cols.length) (sub['*'] || []).forEach(function (x, j) { r.appendChild(el('td', 'text-end nuPvTot', x === null ? '' : fmt(x, j))); });
			body.appendChild(r);
		};
		rows.forEach(function (rk, ri) {
			for (var k = nr - 1; k >= 1; k--) if (ri > 0 && prev.slice(0, k).join('\x1f') !== rk.slice(0, k).join('\x1f')) addSub(k, prev.slice(0, k));
			var r = el('tr');
			if (!nr) r.appendChild(el('td', 'nuPvRowHead', t('pv_total')));
			rk.forEach(function (k, i) { r.appendChild(el('td', 'nuPvRowHead' + (ri > 0 && prev.slice(0, i + 1).join('\x1f') === rk.slice(0, i + 1).join('\x1f') ? ' nuPvRep' : ''), keyLabel(k, sp.rows[i].g))); });
			cols.forEach(function (ck) {
				var c = cellOf(rk, ck);
				sp.vals.forEach(function (v, j) {
					var x = c ? c[j] : null;
					var td = el('td', 'text-end nuPvCell', show(x, j, rk, ck));
					if (x !== null) { td.setAttribute('data-r', JSON.stringify(rk)); td.setAttribute('data-c', JSON.stringify(ck)); td.tabIndex = 0; }
					if (VIEW.heat && x !== null && max[j]) td.style.backgroundColor = 'rgba(13, 110, 253, ' + (0.08 + 0.5 * Math.abs(x) / max[j]).toFixed(3) + ')';
					r.appendChild(td);
				});
			});
			if (sp.cols.length && nr) (rowTot(rk) || new Array(nv).fill(null)).forEach(function (x, j) { r.appendChild(el('td', 'text-end nuPvTot', show(x, j, rk, null))); });
			body.appendChild(r); prev = rk;
		});
		for (var k = nr - 1; k >= 1; k--) if (prev.length) addSub(k, prev.slice(0, k));
		tb.appendChild(body);
		if (nr) {
			var ft = el('tfoot'), fr = el('tr', 'nuPvGrand');
			var fl = el('td', 'nuPvRowHead', t('pv_total')); fl.colSpan = nr; fr.appendChild(fl);
			cols.forEach(function (ck) { (colTot(ck) || new Array(nv).fill(null)).forEach(function (x, j) { fr.appendChild(el('td', 'text-end', show(x, j, null, ck))); }); });
			if (sp.cols.length) (R.grand || []).forEach(function (x, j) { fr.appendChild(el('td', 'text-end nuPvTot', show(x, j, null, null))); });
			ft.appendChild(fr); tb.appendChild(ft);
		}
		wrap.appendChild(tb); out.appendChild(wrap);
		out.appendChild(el('div', 'small text-body-secondary px-3 pb-2', t('pv_drill_tip')));
	}

	// a click on a number: the records behind it
	function drill(td) {
		var box = D.querySelector('#nuPvDrill');
		box.hidden = false; box.innerHTML = '<div class="small text-body-secondary p-2">' + t('pv_working') + '</div>';
		post('drill', {spec: JSON.stringify(R.spec), rk: td.getAttribute('data-r'), ck: td.getAttribute('data-c')}).then(function (d) {
			box.innerHTML = '';
			var h = el('div', 'd-flex align-items-center gap-2 px-3 pt-2');
			h.appendChild(el('strong', 'small', t('pv_drill').replace('%1$d', String((d.rows || []).length)).replace('%2$d', String(d.total || 0))));
			var x = el('button', 'btn-close btn-sm ms-auto'); x.type = 'button'; x.setAttribute('aria-label', t('close'));
			x.addEventListener('click', function () { box.hidden = true; });
			h.appendChild(x); box.appendChild(h);
			var w = el('div', 'table-responsive'), tb = el('table', 'table table-sm table-hover nu-table mb-0 nuPvDrillTable');
			var hr = el('tr'); (d.head || []).forEach(function (c) { hr.appendChild(el('th', '', c)); });
			var th = el('thead'); th.appendChild(hr); tb.appendChild(th);
			var bd = el('tbody');
			(d.rows || []).forEach(function (r) {
				var tr = el('tr'); tr.setAttribute('data-pk', r.pk);
				r.cells.forEach(function (c) { tr.appendChild(el('td', '', c)); });
				tr.addEventListener('click', function () { window.open('index.php?p=form&f=' + encodeURIComponent(S.f) + '&r=' + encodeURIComponent(r.pk), '_blank', 'noopener'); });
				bd.appendChild(tr);
			});
			tb.appendChild(bd); w.appendChild(tb); box.appendChild(w);
			box.scrollIntoView({block: 'nearest'});
		});
	}

	// ------------------------------------------------------------------ chart
	function loadApex() {
		if (window.ApexCharts) return Promise.resolve();
		return new Promise(function (ok, no) { var s = document.createElement('script'); s.src = APEX; s.onload = ok; s.onerror = no; document.head.appendChild(s); });
	}
	function renderChart(out) {
		var box = el('div', 'nuPvChart'); box.id = 'nuPvChart'; out.appendChild(box);
		var sp = R.spec, rows = orderedRows();
		var cats = rows.map(function (rk) { return keyText(rk, sp.rows) || t('pv_total'); });
		var series = [];
		if (sp.cols.length) R.cols.forEach(function (ck) { series.push({name: keyText(ck, sp.cols), data: rows.map(function (rk) { var c = cellOf(rk, ck); return c && c[0] !== null ? c[0] : 0; })}); });
		else sp.vals.forEach(function (v, j) { series.push({name: valTitle(v), data: rows.map(function (rk) { var c = cellOf(rk, []); return c && c[j] !== null ? c[j] : 0; })}); });
		loadApex().then(function () {
			var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
			chart = new window.ApexCharts(box, {
				chart: {type: VIEW.ctype === 'line' ? 'line' : 'bar', stacked: VIEW.ctype === 'stack', height: 420, toolbar: {show: true}, background: 'transparent', animations: {enabled: false}},
				theme: {mode: dark ? 'dark' : 'light'}, series: series, xaxis: {categories: cats}, dataLabels: {enabled: false},
				yaxis: {labels: {formatter: function (v) { return nf.format(v); }}}, legend: {position: 'top'}
			});
			chart.render();
		}).catch(function () { box.textContent = t('pv_err_query'); });
	}

	// ------------------------------------------------------------------ export (the table as shown)
	function exportFile(fmt) {
		if (!R) return;
		var tb = D.querySelector('#nuPvTable');
		if (!tb) { VIEW.mode = 'table'; buildBar(); render(); tb = D.querySelector('#nuPvTable'); }
		if (!tb) return;
		var head = Array.prototype.map.call(tb.tHead.rows[0].cells, function (c) { return c.textContent; });
		var lines = [];
		Array.prototype.forEach.call(tb.querySelectorAll('tbody tr, tfoot tr'), function (tr) {
			var line = [];
			Array.prototype.forEach.call(tr.cells, function (c) { line.push(c.textContent); for (var i = 1; i < c.colSpan; i++) line.push(''); });
			lines.push(line);
		});
		var f = el('form'); f.method = 'post'; f.action = 'index.php?p=pivot&f=' + encodeURIComponent(S.f); f.hidden = true;
		[['_csrf', (window.nuNext || {}).csrf || ''], ['action', 'export'], ['f', S.f], ['fmt', fmt], ['head', JSON.stringify(head)], ['lines', JSON.stringify(lines)]].forEach(function (p) {
			var i = el('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i);
		});
		document.body.appendChild(f); f.submit(); setTimeout(function () { f.remove(); }, 1000);
	}

	// ------------------------------------------------------------------ open
	var Q = new URLSearchParams(location.search), PICK = Q.get('pivot'), ONLY = !!PICK && Q.get('pvonly') === '1';
	function open(btn, pick) {
		try { S = JSON.parse(btn.getAttribute('data-nu-pivot') || '{}'); T = JSON.parse(btn.getAttribute('data-nu-pivot-texts') || '{}'); } catch (e) { return; }
		var last = store('nunext-pivot-' + S.f);
		S.spec = last && last.spec ? last.spec : {rows: [], cols: [], vals: [], flt: []};
		if (last && last.view) VIEW = Object.assign(VIEW, last.view);
		(pick && pick !== '-' ? post('saved').then(function (d) {   // a run button's saved pivot (0.8.1156)
			var s = (d.saved || []).filter(function (x) { return x.id === pick; })[0];
			if (s && s.spec && s.spec.spec) { S.spec = s.spec.spec; if (s.spec.view) VIEW = Object.assign(VIEW, s.spec.view); }
		}) : Promise.resolve()).then(function () { return post('fields'); }).then(function (d) {
			F = d.fields || []; BY = {}; F.forEach(function (f) { BY[f.c] = f; });
			['rows', 'cols', 'flt', 'vals'].forEach(function (z) { S.spec[z] = (S.spec[z] || []).filter(function (it) { return it.c === -1 || BY[it.c]; }); });
			if (D) D.remove();
			build(); renderZones(); run();
			if (ONLY) {                                            // pivot only (run button: iframe, popup, new tab): no list behind, no close
				document.documentElement.classList.add('nuPvOnly');
				D.querySelector('.modal-dialog').className = 'modal-dialog modal-fullscreen';
				D.querySelector('.btn-close').remove();
				nuBs.modal(D, {backdrop: false, keyboard: false}).show();
			} else nuBs.modal(D).show();
		});
	}
	if (PICK) {
		var go = function () { var b = document.getElementById('nuPivotOpen'); if (b) open(b, PICK); else document.documentElement.classList.remove('nuPvOnly'); };
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
	}
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('#nuPivotOpen');
		if (b) { e.preventDefault(); open(b); return; }
		var td = e.target.closest && e.target.closest('#nuPvTable td.nuPvCell[data-r]');
		if (td) drill(td);
		var pop = D && D.querySelector('.nuPvFltPop');
		if (pop && !e.target.closest('.nuPvFltPop') && !e.target.closest('.nuPvFltBtn')) pop.remove();
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target.matches && e.target.matches('#nuPvTable td.nuPvCell[data-r]')) drill(e.target);
	});
	window.nuPivotOpen = function () { var b = document.getElementById('nuPivotOpen'); if (b) open(b); };
})();
