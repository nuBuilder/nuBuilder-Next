/* Data export (0.8.758): the list's JSON upload button and the editor of an export's datasets (tables with columns / conditions / sort,
   typed SQL, SQL builder queries; preview and count through POST JSON actions of pages/nudataexport.php). The model is kept in the hidden
   input #dxpDatasets (JSON) and sent with the form. */
(function () {
	'use strict';
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') e.textContent = attrs[k]; else if (k === 'class') e.className = attrs[k]; else if (k === 'html') e.innerHTML = attrs[k]; else e.setAttribute(k, attrs[k]);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}

	// ------------------------------------------------------------------ list: import a JSON file
	var impBtn = $('#dxpImportBtn'), impFile = $('#dxpImportFile');
	if (impBtn && impFile) {
		impBtn.addEventListener('click', function () { impFile.click(); });
		impFile.addEventListener('change', function () { if (impFile.files.length) $('#dxpImportForm').submit(); });
	}

	var dataEl = $('#dxpData');
	if (!dataEl) return;
	var D = JSON.parse(dataEl.textContent);
	var T = D.texts;
	var form = $('#dxpForm'), hidden = $('#dxpDatasets'), tabs = $('#dxpDsTabs'), host = $('#dxpDs');
	var ds = D.export.datasets || [];
	var cur = 0;
	var cache = {tables: null, selects: null, columns: {}};
	var ro = !!D.ro;
	var editors = {};

	// ------------------------------------------------------------------ format switch (CSV options card)
	var fmt = $('#dxpFormat');
	if (fmt) fmt.addEventListener('change', function () { $('#dxpCsvCard').hidden = fmt.value !== 'csv'; });

	// ------------------------------------------------------------------ server calls
	function post(action, data) {
		var fd = new FormData();
		fd.append('_csrf', form.querySelector('[name=_csrf]').value);
		fd.append('action', action);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(D.url, {method: 'POST', body: fd, credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(function (r) { return r.json(); });
	}
	function tables() {
		if (cache.tables) return Promise.resolve(cache);
		return post('tables').then(function (r) { cache.tables = r.tables || []; cache.selects = r.selects || []; return cache; });
	}
	function columnsOf(table) {
		if (!table) return Promise.resolve([]);
		if (cache.columns[table]) return Promise.resolve(cache.columns[table]);
		return post('columns', {table: table}).then(function (r) { cache.columns[table] = r.ok ? r.columns : []; return cache.columns[table]; });
	}

	// ------------------------------------------------------------------ model
	function blank() { return {name: '', source: 'table', table: '', columns: [], where: {m: 'all', c: []}, sort: [], sql: '', select: '', reimport: false}; }
	function label(d, i) { return d.name || (d.source === 'table' ? d.table : '') || (T.dxp_dataset + ' ' + (i + 1)); }
	function dirty() {
		hidden.value = JSON.stringify(ds);
		hidden.dispatchEvent(new Event('input', {bubbles: true}));
	}
	function sync() { hidden.value = JSON.stringify(ds); }
	form.addEventListener('submit', function () { $$('.dxpSql').forEach(function (ta) { if (editors[ta.id]) editors[ta.id].save(); }); sync(); });

	// ------------------------------------------------------------------ tabs
	function renderTabs() {
		tabs.innerHTML = '';
		ds.forEach(function (d, i) {
			var a = el('a', {class: 'nav-link' + (i === cur ? ' active' : ''), href: '#', 'data-i': i, text: label(d, i)});
			a.addEventListener('click', function (e) { e.preventDefault(); cur = i; render(); });
			tabs.appendChild(el('li', {class: 'nav-item'}, [a]));
		});
	}
	var addBtn = $('#dxpDsAdd');
	if (addBtn) addBtn.addEventListener('click', function () {
		if (ds.length >= D.maxDatasets) return;
		ds.push(blank());
		cur = ds.length - 1;
		dirty();
		render();
	});

	// ------------------------------------------------------------------ the panel of one dataset
	function render() {
		if (!ds.length && !ro) ds.push(blank());
		if (cur >= ds.length) cur = ds.length - 1;
		renderTabs();
		host.innerHTML = '';
		if (!ds.length) return;
		var d = ds[cur], i = cur;
		var panel = el('div', {class: 'dxpPanel', 'data-i': i});
		// name + source + remove
		var name = el('input', {class: 'form-control form-control-sm', id: 'dxpDsName', value: d.name, maxlength: 31, placeholder: T.dxp_name_ds});
		name.addEventListener('input', function () { d.name = name.value; dirty(); renderTabs(); });
		var srcSel = el('select', {class: 'form-select form-select-sm', id: 'dxpDsSource'});
		['table', 'sql', 'select'].forEach(function (s) { srcSel.appendChild(el('option', {value: s, text: T['dxp_src_' + s]})); });
		srcSel.value = d.source;
		srcSel.addEventListener('change', function () { d.source = srcSel.value; dirty(); render(); });
		var head = el('div', {class: 'row g-2 align-items-end mb-3'}, [
			el('div', {class: 'col-sm-4'}, [el('label', {class: 'form-label small mb-1', for: 'dxpDsName', text: T.dxp_name_ds}), name]),
			el('div', {class: 'col-sm-4'}, [el('label', {class: 'form-label small mb-1', for: 'dxpDsSource', text: T.dxp_type}), srcSel]),
		]);
		if (!ro && ds.length > 1) {
			var rm = el('button', {type: 'button', class: 'btn btn-sm btn-outline-danger', id: 'dxpDsRemove', title: T.dxp_remove_dataset}, [el('i', {class: 'bi bi-trash'})]);
			rm.addEventListener('click', function () { ds.splice(i, 1); cur = Math.max(0, i - 1); dirty(); render(); });
			head.appendChild(el('div', {class: 'col-sm-4 text-sm-end'}, [rm]));
		}
		panel.appendChild(head);
		if (ro) $$('input, select', head).forEach(function (x) { x.disabled = true; });
		if (d.source === 'table') renderTable(panel, d);
		else renderQuery(panel, d);
		// preview / count bar
		var bar = el('div', {class: 'd-flex flex-wrap gap-2 align-items-center mt-3 pt-2 border-top'});
		var pv = el('button', {type: 'button', class: 'btn btn-sm btn-outline-primary', id: 'dxpPreviewBtn'}, [el('i', {class: 'bi bi-eye me-1'}), T.dxp_preview]);
		var cnt = el('button', {type: 'button', class: 'btn btn-sm btn-outline-secondary', id: 'dxpCountBtn'}, [el('i', {class: 'bi bi-123 me-1'}), T.dxp_count]);
		var msg = el('span', {class: 'small ms-1', id: 'dxpMsg'});
		pv.addEventListener('click', function () { preview(d); });
		cnt.addEventListener('click', function () {
			msg.textContent = T.dxp_loading; msg.className = 'small ms-1 text-body-secondary';
			askParams(d).then(function (vals) { return post('count', {dataset: JSON.stringify(d), values: JSON.stringify(vals)}); }).then(function (r) {
				msg.textContent = r.ok && r.count !== null ? r.count + ' ' + T.dxp_rows : (r.message || '⚠');
				msg.className = 'small ms-1 ' + (r.ok && r.count !== null ? 'text-success' : 'text-danger');
			}, function () { msg.textContent = ''; });
		});
		bar.appendChild(pv); bar.appendChild(cnt); bar.appendChild(msg);
		panel.appendChild(bar);
		host.appendChild(panel);
	}

	// ------------------------------------------------------------------ table source
	function renderTable(panel, d) {
		var sel = el('select', {class: 'form-select form-select-sm', id: 'dxpTable'});
		sel.appendChild(el('option', {value: '', text: T.dxp_loading}));
		var wrap = el('div', {class: 'mb-3'}, [el('label', {class: 'form-label small mb-1', for: 'dxpTable', text: T.dxp_table}), sel]);
		panel.appendChild(wrap);
		var body = el('div', {id: 'dxpTableBody'});
		panel.appendChild(body);
		tables().then(function (c) {
			sel.innerHTML = '';
			sel.appendChild(el('option', {value: '', text: '…'}));
			c.tables.forEach(function (t) {
				var o = el('option', {value: t.name, text: t.name});
				if (t.view || t.system) o.setAttribute('data-sub', (t.view ? T.dxp_view : '') + (t.view && t.system ? ' · ' : '') + (t.system ? T.dxp_system : ''));
				sel.appendChild(o);
			});
			sel.value = d.table;
			sel.classList.add('nuListTomSelect');
			if (window.nuListSelects) window.nuListSelects(wrap);
			if (ro) sel.disabled = true;
			sel.addEventListener('change', function () {
				d.table = sel.value; d.columns = []; d.where = {m: 'all', c: []}; d.sort = [];
				dirty(); renderTabs(); loadCols();
			});
			loadCols();
		});
		function loadCols() {
			body.innerHTML = '';
			if (!d.table) return;
			body.appendChild(el('div', {class: 'small text-body-secondary', text: T.dxp_loading}));
			columnsOf(d.table).then(function (cols) {
				body.innerHTML = '';
				if (!cols.length) { body.appendChild(el('div', {class: 'text-danger small', text: T.dxp_err_table})); return; }
				renderColumns(body, d, cols);
				renderConditions(body, d, cols);
				renderSort(body, d, cols);
				var chk = el('input', {class: 'form-check-input', type: 'checkbox', id: 'dxpReimport'});
				chk.checked = !!d.reimport; chk.disabled = ro;
				chk.addEventListener('change', function () { d.reimport = chk.checked; dirty(); });
				body.appendChild(el('div', {class: 'form-check mt-3'}, [chk, el('label', {class: 'form-check-label', for: 'dxpReimport', text: T.dxp_reimport}),
					el('span', {class: 'nuInfoTip ms-1', tabindex: '0', role: 'img', 'data-bs-toggle': 'tooltip', 'data-bs-custom-class': 'nuTipWide', 'aria-label': T.dxp_reimport_tip, title: T.dxp_reimport_tip}, [el('i', {class: 'bi bi-info-circle'})])]));
				if (window.nuBs && nuBs.tooltip) $$('[data-bs-toggle=tooltip]', body).forEach(function (x) { nuBs.tooltip(x); });
			});
		}
	}

	/** The column list: ticked first (stored order), the rest unticked; heading and format per column; ↑ ↓ to reorder. */
	function renderColumns(body, d, cols) {
		var byName = {};
		cols.forEach(function (c) { byName[c.name] = c; });
		var rows = [];
		d.columns.forEach(function (c) { if (byName[c.col]) rows.push({on: true, col: c.col, head: c.head || '', fmt: c.fmt || '', meta: byName[c.col]}); });
		var seen = {};
		rows.forEach(function (r) { seen[r.col] = true; });
		var none = !d.columns.length;
		cols.forEach(function (c) { if (!seen[c.name]) rows.push({on: none && !c.secret, col: c.name, head: '', fmt: '', meta: c}); });
		var card = el('div', {class: 'mb-3'});
		var top = el('div', {class: 'd-flex align-items-center gap-2 mb-1'}, [el('span', {class: 'small fw-semibold', text: T.dxp_columns}), el('span', {class: 'small text-body-secondary', id: 'dxpColCount'})]);
		if (!ro) {
			var all = el('button', {type: 'button', class: 'btn btn-link btn-sm p-0', id: 'dxpColsAll', text: T.dxp_all_cols});
			var nn = el('button', {type: 'button', class: 'btn btn-link btn-sm p-0', id: 'dxpColsNone', text: T.dxp_none_cols});
			all.addEventListener('click', function () { rows.forEach(function (r) { r.on = true; }); store(); draw(); });
			nn.addEventListener('click', function () { rows.forEach(function (r) { r.on = false; }); store(); draw(); });
			top.appendChild(el('span', {class: 'ms-auto'})); top.appendChild(all); top.appendChild(nn);
		}
		card.appendChild(top);
		var table = el('table', {class: 'table table-sm nu-table dxpCols mb-0'});
		var tbody = el('tbody');
		table.appendChild(el('thead', {}, [el('tr', {}, [el('th', {}), el('th', {text: T.dxp_column}), el('th', {text: T.dxp_heading}), el('th', {text: T.dxp_format_col}), el('th', {})])]));
		table.appendChild(tbody);
		card.appendChild(el('div', {class: 'table-responsive dxpColsWrap'}, [table]));
		body.appendChild(card);
		function store() {
			d.columns = rows.filter(function (r) { return r.on; }).map(function (r) { return {col: r.col, head: r.head, fmt: r.fmt}; });
			// "all ticked, nothing renamed, nothing formatted, natural order" = the empty list (every column); keep it explicit otherwise
			dirty();
			var n = rows.filter(function (r) { return r.on; }).length;
			$('#dxpColCount').textContent = n + ' / ' + rows.length;
		}
		function draw() {
			tbody.innerHTML = '';
			rows.forEach(function (r, idx) {
				var tr = el('tr', {'data-col': r.col, class: r.on ? '' : 'text-body-secondary'});
				var chk = el('input', {class: 'form-check-input dxpColOn', type: 'checkbox', 'aria-label': r.col});
				chk.checked = r.on; chk.disabled = ro;
				chk.addEventListener('change', function () { r.on = chk.checked; store(); tr.className = r.on ? '' : 'text-body-secondary'; });
				tr.appendChild(el('td', {class: 'text-center'}, [chk]));
				var nameCell = el('td', {class: 'font-monospace small text-nowrap'}, [r.col]);
				if (r.meta.pk) nameCell.appendChild(el('span', {class: 'badge text-bg-primary ms-1', text: T.dxp_pk}));
				if (r.meta.secret) nameCell.appendChild(el('i', {class: 'bi bi-lock ms-1 text-warning', title: T.dxp_secret, 'aria-label': T.dxp_secret, role: 'img'}));
				nameCell.appendChild(el('span', {class: 'badge text-bg-light ms-1 fw-normal', text: T['kind_' + r.meta.kind] || r.meta.kind}));
				tr.appendChild(nameCell);
				var h = el('input', {class: 'form-control form-control-sm dxpColHead', value: r.head, placeholder: r.col, maxlength: 100, 'aria-label': T.dxp_heading});
				h.disabled = ro;
				h.addEventListener('input', function () { r.head = h.value; store(); });
				tr.appendChild(el('td', {}, [h]));
				tr.appendChild(el('td', {}, [fmtField(r.meta.kind, r.fmt, function (v) { r.fmt = v; store(); })]));
				var mv = el('td', {class: 'text-nowrap'});
				if (!ro) {
					var up = el('button', {type: 'button', class: 'btn btn-sm btn-link p-0 me-1 dxpColUp', title: T.dxp_up, 'aria-label': T.dxp_up}, [el('i', {class: 'bi bi-arrow-up'})]);
					var dn = el('button', {type: 'button', class: 'btn btn-sm btn-link p-0 dxpColDown', title: T.dxp_down, 'aria-label': T.dxp_down}, [el('i', {class: 'bi bi-arrow-down'})]);
					up.disabled = idx === 0; dn.disabled = idx === rows.length - 1;
					up.addEventListener('click', function () { rows.splice(idx - 1, 0, rows.splice(idx, 1)[0]); store(); draw(); });
					dn.addEventListener('click', function () { rows.splice(idx + 1, 0, rows.splice(idx, 1)[0]); store(); draw(); });
					mv.appendChild(up); mv.appendChild(dn);
				}
				tr.appendChild(mv);
				tbody.appendChild(tr);
			});
			$('#dxpColCount').textContent = rows.filter(function (r) { return r.on; }).length + ' / ' + rows.length;
		}
		draw();
	}

	/** Format select: by type / the kind's suggestions / custom text. */
	function fmtField(kind, value, onChange) {
		var opts = (D.formats[kind] || []).slice();
		var sel = el('select', {class: 'form-select form-select-sm dxpColFmt', 'aria-label': T.dxp_format_col});
		sel.appendChild(el('option', {value: '', text: T.dxp_fmt_by_type}));
		opts.forEach(function (f) { sel.appendChild(el('option', {value: f, text: f === '@' ? T.dxp_fmt_text : f})); });
		sel.appendChild(el('option', {value: '\u0000custom', text: T.dxp_fmt_custom}));
		var custom = el('input', {class: 'form-control form-control-sm mt-1 dxpColFmtCustom', value: '', maxlength: 60, 'aria-label': T.dxp_fmt_custom});
		var known = value === '' || opts.indexOf(value) >= 0;
		sel.value = known ? value : '\u0000custom';
		custom.hidden = known;
		custom.value = known ? '' : value;
		sel.disabled = ro; custom.disabled = ro;
		sel.addEventListener('change', function () {
			if (sel.value === '\u0000custom') { custom.hidden = false; custom.focus(); onChange(custom.value); } else { custom.hidden = true; onChange(sel.value); }
		});
		custom.addEventListener('input', function () { onChange(custom.value); });
		return el('div', {}, [sel, custom]);
	}

	function renderConditions(body, d, cols) {
		var card = el('div', {class: 'mb-3'});
		var m = el('select', {class: 'form-select form-select-sm w-auto d-inline-block', id: 'dxpMatch'});
		m.appendChild(el('option', {value: 'all', text: T.dxp_match_all}));
		m.appendChild(el('option', {value: 'any', text: T.dxp_match_any}));
		m.value = d.where.m; m.disabled = ro;
		m.addEventListener('change', function () { d.where.m = m.value; dirty(); });
		var top = el('div', {class: 'd-flex align-items-center gap-2 mb-1 flex-wrap'}, [el('span', {class: 'small fw-semibold', text: T.dxp_conditions}), m]);
		var list = el('div', {id: 'dxpConds'});
		if (!ro) {
			var add = el('button', {type: 'button', class: 'btn btn-sm btn-outline-secondary ms-auto', id: 'dxpCondAdd'}, [el('i', {class: 'bi bi-plus-lg me-1'}), T.dxp_add_condition]);
			add.addEventListener('click', function () { d.where.c.push([cols[0].name, 'eq', '', '']); dirty(); draw(); });
			top.appendChild(add);
		}
		card.appendChild(top); card.appendChild(list); body.appendChild(card);
		function kindOf(col) { var c = cols.filter(function (x) { return x.name === col; })[0]; return c ? c.kind : 'text'; }
		function draw() {
			list.innerHTML = '';
			d.where.c.forEach(function (c, idx) {
				var row = el('div', {class: 'row g-1 mb-1 dxpCond'});
				var cs = el('select', {class: 'form-select form-select-sm dxpCondCol', 'aria-label': T.dxp_column});
				cols.forEach(function (x) { cs.appendChild(el('option', {value: x.name, text: x.name})); });
				cs.value = c[0];
				var os = el('select', {class: 'form-select form-select-sm dxpCondOp', 'aria-label': T.dxp_type});
				var v1 = el('input', {class: 'form-control form-control-sm dxpCondV1', value: c[2]});
				var v2 = el('input', {class: 'form-control form-control-sm dxpCondV2', value: c[3]});
				function ops() {
					var k = kindOf(cs.value);
					os.innerHTML = '';
					(D.ops[k] || D.ops.text).forEach(function (o) { os.appendChild(el('option', {value: o, text: T['op_' + o]})); });
					if ([].slice.call(os.options).some(function (o) { return o.value === c[1]; })) os.value = c[1]; else { os.value = os.options[0].value; c[1] = os.value; }
					vis();
				}
				function vis() {
					var noVal = ['empty', 'nempty', 'true', 'false'].indexOf(os.value) >= 0;
					v1.hidden = noVal; v2.hidden = os.value !== 'between';
					if (os.value === 'in') v1.placeholder = 'a, b, c'; else v1.placeholder = '';
				}
				cs.addEventListener('change', function () { c[0] = cs.value; ops(); dirty(); });
				os.addEventListener('change', function () { c[1] = os.value; vis(); dirty(); });
				v1.addEventListener('input', function () { c[2] = v1.value; dirty(); });
				v2.addEventListener('input', function () { c[3] = v2.value; dirty(); });
				ops();
				row.appendChild(el('div', {class: 'col-sm-3'}, [cs]));
				row.appendChild(el('div', {class: 'col-sm-3'}, [os]));
				row.appendChild(el('div', {class: 'col-sm-2'}, [v1]));
				row.appendChild(el('div', {class: 'col-sm-2'}, [v2]));
				if (!ro) {
					var rm = el('button', {type: 'button', class: 'btn btn-sm btn-link text-danger p-0 dxpCondRemove', title: T.remove, 'aria-label': T.remove}, [el('i', {class: 'bi bi-x-lg'})]);
					rm.addEventListener('click', function () { d.where.c.splice(idx, 1); dirty(); draw(); });
					row.appendChild(el('div', {class: 'col-sm-1 pt-1'}, [rm]));
				} else $$('input, select', row).forEach(function (x) { x.disabled = true; });
				list.appendChild(row);
			});
		}
		draw();
	}

	function renderSort(body, d, cols) {
		var card = el('div', {class: 'mb-2'});
		var top = el('div', {class: 'd-flex align-items-center gap-2 mb-1'}, [el('span', {class: 'small fw-semibold', text: T.dxp_sort})]);
		var list = el('div', {id: 'dxpSorts'});
		if (!ro) {
			var add = el('button', {type: 'button', class: 'btn btn-sm btn-outline-secondary ms-auto', id: 'dxpSortAdd'}, [el('i', {class: 'bi bi-plus-lg me-1'}), T.dxp_add_sort]);
			add.addEventListener('click', function () { if (d.sort.length < 10) { d.sort.push([cols[0].name, 'asc']); dirty(); draw(); } });
			top.appendChild(add);
		}
		card.appendChild(top); card.appendChild(list); body.appendChild(card);
		function draw() {
			list.innerHTML = '';
			d.sort.forEach(function (s, idx) {
				var row = el('div', {class: 'row g-1 mb-1 dxpSort'});
				var cs = el('select', {class: 'form-select form-select-sm dxpSortCol', 'aria-label': T.dxp_column});
				cols.forEach(function (x) { cs.appendChild(el('option', {value: x.name, text: x.name})); });
				cs.value = s[0];
				var dir = el('select', {class: 'form-select form-select-sm dxpSortDir', 'aria-label': T.dxp_sort});
				dir.appendChild(el('option', {value: 'asc', text: T.dxp_asc})); dir.appendChild(el('option', {value: 'desc', text: T.dxp_desc}));
				dir.value = s[1];
				cs.addEventListener('change', function () { s[0] = cs.value; dirty(); });
				dir.addEventListener('change', function () { s[1] = dir.value; dirty(); });
				row.appendChild(el('div', {class: 'col-sm-3'}, [cs]));
				row.appendChild(el('div', {class: 'col-sm-3'}, [dir]));
				if (!ro) {
					var rm = el('button', {type: 'button', class: 'btn btn-sm btn-link text-danger p-0 dxpSortRemove', title: T.remove, 'aria-label': T.remove}, [el('i', {class: 'bi bi-x-lg'})]);
					rm.addEventListener('click', function () { d.sort.splice(idx, 1); dirty(); draw(); });
					row.appendChild(el('div', {class: 'col-sm-1 pt-1'}, [rm]));
				} else $$('select', row).forEach(function (x) { x.disabled = true; });
				list.appendChild(row);
			});
		}
		draw();
	}

	// ------------------------------------------------------------------ query sources (typed SQL, SQL builder query)
	function renderQuery(panel, d) {
		var colsHost = el('div', {id: 'dxpQueryCols'});
		if (d.source === 'select') {
			var sel = el('select', {class: 'form-select form-select-sm', id: 'dxpSelect'});
			sel.appendChild(el('option', {value: '', text: T.dxp_loading}));
			var wrap = el('div', {class: 'mb-2'}, [el('label', {class: 'form-label small mb-1', for: 'dxpSelect', text: T.dxp_select_pick}), sel]);
			panel.appendChild(wrap);
			tables().then(function (c) {
				sel.innerHTML = '';
				sel.appendChild(el('option', {value: '', text: '…'}));
				c.selects.forEach(function (q) { var o = el('option', {value: q.id, text: q.code || q.id}); if (q.name) o.setAttribute('data-sub', q.name); sel.appendChild(o); });
				sel.value = d.select;
				sel.classList.add('nuListTomSelect');
				if (window.nuListSelects) window.nuListSelects(wrap);
				if (ro) sel.disabled = true;
				sel.addEventListener('change', function () { d.select = sel.value; d.columns = []; dirty(); renderTabs(); colsHost.innerHTML = ''; });
			});
		} else {
			var ta = el('textarea', {class: 'form-control dxpSql', id: 'dxpSql_' + cur, rows: 8, spellcheck: 'false'});
			ta.value = d.sql;
			var tools = el('div', {class: 'nuCodeTools dxpSqlTools'});
			panel.appendChild(el('div', {class: 'mb-1 small text-body-secondary', text: T.dxp_sql_hint}));
			panel.appendChild(el('div', {class: 'd-flex gap-2 align-items-start mb-2'}, [tools, el('div', {class: 'flex-grow-1 dxpSqlWrap'}, [ta])]));
			if (window.CodeMirror) {
				var cm = CodeMirror.fromTextArea(ta, {mode: 'text/x-sql', lineNumbers: true, lineWrapping: true, matchBrackets: true, indentUnit: 4, tabSize: 4, indentWithTabs: true, readOnly: ro ? 'nocursor' : false});
				cm.getWrapperElement().classList.add('dxpCM');
				cm.on('change', function () { cm.save(); d.sql = ta.value; dirty(); });
				editors[ta.id] = cm;
				if (window.NuCodeTools) NuCodeTools.attach(cm, tools, {lang: 'SQL', db: D.db, readOnly: ro});
			} else {
				ta.readOnly = ro;
				ta.addEventListener('input', function () { d.sql = ta.value; dirty(); });
			}
		}
		var chk = el('button', {type: 'button', class: 'btn btn-sm btn-outline-secondary', id: 'dxpCheck'}, [el('i', {class: 'bi bi-check2-circle me-1'}), T.dxp_check]);
		var msg = el('span', {class: 'small ms-2', id: 'dxpCheckMsg'});
		chk.addEventListener('click', function () {
			msg.textContent = T.dxp_loading; msg.className = 'small ms-2 text-body-secondary';
			post('check', {dataset: JSON.stringify(d)}).then(function (r) {
				msg.textContent = r.ok ? T.dxp_check_ok + (r.params && r.params.length ? ' · ' + T.dxp_params_in + ' ' + r.params.map(function (p) { return '#' + p + '#'; }).join(', ') : '') : r.message;
				msg.className = 'small ms-2 ' + (r.ok ? 'text-success' : 'text-danger');
			});
		});
		panel.appendChild(el('div', {class: 'mb-2'}, [chk, msg]));
		panel.appendChild(el('div', {class: 'small text-body-secondary mb-1', text: T.dxp_preview_hint}));
		panel.appendChild(colsHost);
		if (d.columns.length) drawQueryCols(colsHost, d, d.columns.map(function (c) { return {col: c.col, head: c.head || c.col, fmt: c.fmt || '', kind: 'text'}; }), true);
	}

	/** After a preview of a query: its result columns with heading / format (the picked list is saved only once touched). */
	function drawQueryCols(hostEl, d, cols, stored) {
		hostEl.innerHTML = '';
		var byName = {};
		d.columns.forEach(function (c) { byName[c.col] = c; });
		var rows = cols.map(function (c) { var s = byName[c.col]; return {col: c.col, head: s ? s.head : '', fmt: s ? s.fmt : '', kind: c.kind || 'text', on: !d.columns.length || !!s}; });
		if (!stored) d.columns.forEach(function (c) { if (!cols.some(function (x) { return x.col === c.col; })) rows.push({col: c.col, head: c.head, fmt: c.fmt, kind: 'text', on: true, missing: true}); });
		var table = el('table', {class: 'table table-sm nu-table dxpCols mb-0'});
		table.appendChild(el('thead', {}, [el('tr', {}, [el('th', {}), el('th', {text: T.dxp_column}), el('th', {text: T.dxp_heading}), el('th', {text: T.dxp_format_col})])]));
		var tbody = el('tbody'); table.appendChild(tbody);
		function store() { d.columns = rows.filter(function (r) { return r.on; }).map(function (r) { return {col: r.col, head: r.head, fmt: r.fmt}; }); dirty(); }
		rows.forEach(function (r) {
			var tr = el('tr', {'data-col': r.col, class: r.missing ? 'table-warning' : ''});
			var on = el('input', {class: 'form-check-input dxpColOn', type: 'checkbox', 'aria-label': r.col}); on.checked = r.on; on.disabled = ro;
			on.addEventListener('change', function () { r.on = on.checked; store(); });
			tr.appendChild(el('td', {class: 'text-center'}, [on]));
			var nc = el('td', {class: 'font-monospace small'}, [r.col]);
			if (!stored) nc.appendChild(el('span', {class: 'badge text-bg-light ms-1 fw-normal', text: T['kind_' + r.kind] || r.kind}));
			tr.appendChild(nc);
			var h = el('input', {class: 'form-control form-control-sm dxpColHead', value: r.head, placeholder: r.col, maxlength: 100, 'aria-label': T.dxp_heading}); h.disabled = ro;
			h.addEventListener('input', function () { r.head = h.value; store(); });
			tr.appendChild(el('td', {}, [h]));
			tr.appendChild(el('td', {}, [fmtField(r.kind, r.fmt, function (v) { r.fmt = v; store(); })]));
			tbody.appendChild(tr);
		});
		hostEl.appendChild(el('div', {class: 'small fw-semibold mb-1', text: T.dxp_columns}));
		hostEl.appendChild(el('div', {class: 'table-responsive dxpColsWrap'}, [table]));
	}

	// ------------------------------------------------------------------ parameters of a query (asked before preview / count)
	function askParams(d) {
		if (d.source === 'table') return Promise.resolve({});
		return post('check', {dataset: JSON.stringify(d)}).then(function (r) {
			if (!r.ok) return Promise.reject(r.message);
			var vals = {};
			(r.params || []).forEach(function (p) { var v = window.prompt('#' + p + '#', ''); if (v === null) throw new Error('cancel'); vals[p] = v; });
			return vals;
		});
	}

	// ------------------------------------------------------------------ preview dialog
	function preview(d) {
		var dlg = $('#dxpPreviewDlg'), body = $('#dxpPreviewBody');
		var msg = $('#dxpMsg');
		if (msg) { msg.textContent = T.dxp_loading; msg.className = 'small ms-1 text-body-secondary'; }
		$$('.dxpSql').forEach(function (ta) { if (editors[ta.id]) editors[ta.id].save(); });
		askParams(d).then(function (vals) { return post('preview', {dataset: JSON.stringify(d), values: JSON.stringify(vals)}); }).then(function (r) {
			if (msg) msg.textContent = '';
			if (!r.ok) { if (msg) { msg.textContent = r.message || '⚠'; msg.className = 'small ms-1 text-danger'; } return; }
			body.innerHTML = '';
			if (d.source !== 'table') { var qc = $('#dxpQueryCols'); if (qc) drawQueryCols(qc, d, r.cols, false); }
			if (!r.rows.length) { body.appendChild(el('p', {class: 'text-body-secondary mb-0', text: T.dxp_no_rows})); }
			var t = el('table', {class: 'table table-sm nu-table dxpPreview mb-0'});
			var hr = el('tr');
			r.cols.forEach(function (c) { hr.appendChild(el('th', {text: c.head || c.col, title: c.col + ' · ' + (T['kind_' + c.kind] || c.kind)})); });
			t.appendChild(el('thead', {}, [hr]));
			var tb = el('tbody');
			r.rows.forEach(function (row) {
				var tr = el('tr');
				row.forEach(function (v, k) { tr.appendChild(el('td', {class: v === null ? 'text-body-tertiary fst-italic' : (['int', 'decimal'].indexOf(r.cols[k].kind) >= 0 ? 'text-end' : ''), text: v === null ? 'NULL' : v})); });
				tb.appendChild(tr);
			});
			t.appendChild(tb);
			body.appendChild(el('div', {class: 'table-responsive'}, [t]));
			if (window.nuBs) nuBs.modal(dlg).show();
		}, function (e) { if (msg && e !== 'cancel' && !(e && e.message === 'cancel')) { msg.textContent = typeof e === 'string' ? e : '⚠'; msg.className = 'small ms-1 text-danger'; } else if (msg) msg.textContent = ''; });
	}

	render();
}());
