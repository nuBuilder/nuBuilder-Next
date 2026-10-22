/* nuBuilder Next – SQL builder (pages/nusqlbuilder.php), nuBuilder's zzzzsys_select:
 *   sse_json {"tables": [{id: "box…", position: {top, left}, tablename, alias, checkall, checkboxes: [bool per column]}],
 *             "joins": {"field_<col index>_<box id>--field_<col index>_<box id>": "" | "LEFT"}}
 * Tables are boxes on a canvas; tick the columns, drag a column onto a column of another table to join them (click the join to
 * switch JOIN / LEFT JOIN), clauses below; the SQL is written like nuBuilder's builder unless "edit by hand" is on.
 */
(function () {
	'use strict';
	var dataEl = document.getElementById('sqbData');
	var root = document.getElementById('sqbApp');
	if (!dataEl || !root) return;
	var D = JSON.parse(dataEl.textContent);
	var M = D.msg || {};
	var schema = D.schema || {};
	var ro = !!D.readonly;
	var jsonInput = document.getElementById('sse_json');
	var sqlInput = document.getElementById('sse_sql');
	var manual = document.getElementById('sse_edit');
	var clausesInput = document.getElementById('sqbClauses');
	var form = document.getElementById('nuRecordForm');
	var state = {tables: [], joins: {}, clauses: []};
	var missing = [];

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v;
			else if (k === 'class') e.className = v;
			else e.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c !== null && c !== undefined && c !== false) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function icon(n) { return el('i', {'class': 'bi bi-' + n}); }
	function uid() { return 'box' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }

	// ----------------------------------------------------------------- load
	(function load() {
		var j = null;
		try { j = jsonInput.value ? JSON.parse(jsonInput.value) : null; } catch (e) { j = null; }
		if (j && Array.isArray(j.tables)) {
			j.tables.forEach(function (t) {
				if (!schema[t.tablename]) { missing.push(t.tablename); return; }
				state.tables.push({id: String(t.id), tablename: t.tablename, alias: t.alias || '', checkall: !!t.checkall, checkboxes: (t.checkboxes || []).map(Boolean),
					position: {top: Math.max(0, parseInt(t.position && t.position.top, 10) || 0), left: Math.max(0, parseInt(t.position && t.position.left, 10) || 0)}});
			});
		}
		if (j && j.joins && typeof j.joins === 'object' && !Array.isArray(j.joins)) {
			Object.keys(j.joins).forEach(function (k) { state.joins[k] = j.joins[k] === 'LEFT' ? 'LEFT' : ''; });
		}
		state.clauses = (D.clauses || []).map(function (c) { return {type: String(c.ssc_type || '1'), field: c.ssc_field || '', clause: c.ssc_clause || '', sort: c.ssc_sort || '', order: c.ssc_order || ''}; });
	})();

	// ----------------------------------------------------------------- skeleton
	var tableSearch = el('input', {type: 'search', 'class': 'form-control form-control-sm mb-2', placeholder: M.search, 'aria-label': M.search});
	var sysToggle = el('input', {type: 'checkbox', 'class': 'form-check-input', id: 'sqbSys'});
	var tableList = el('div', {'class': 'sqbTableList'});
	var left = el('aside', {'class': 'sqbLeft'}, [el('div', {'class': 'fw-semibold small mb-1', text: M.tables}), tableSearch,
		el('div', {'class': 'form-check form-switch small mb-2'}, [sysToggle, el('label', {'class': 'form-check-label', 'for': 'sqbSys', text: M.system})]), tableList]);
	var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
	svg.setAttribute('class', 'sqbLines');
	var board = el('div', {'class': 'sqbBoard'});
	board.appendChild(svg);
	var canvas = el('div', {'class': 'sqbCanvas'}, [board]);
	var hint = el('div', {'class': 'small text-body-secondary px-2 py-1 sqbHint', text: M.drag_hint});
	var clauseBox = el('div', {'class': 'sqbClauses'});
	root.appendChild(el('div', {'class': 'sqbMain'}, [left, el('div', {'class': 'sqbCenter'}, [canvas, hint])]));
	root.appendChild(clauseBox);
	if (missing.length) root.insertBefore(el('div', {'class': 'alert alert-warning small py-2', text: (M.missing_table || '%s').replace('%s', missing.join(', '))}), root.firstChild);

	function renderTableList() {
		var q = tableSearch.value.trim().toLowerCase();
		tableList.innerHTML = '';
		Object.keys(schema).filter(function (t) { return (sysToggle.checked || t.toLowerCase().indexOf('zzzzsys_') !== 0) && (!q || t.toLowerCase().indexOf(q) >= 0); }).forEach(function (t) {
			var b = el('button', {type: 'button', 'class': 'sqbTableItem', title: M.add_table + ': ' + t, draggable: ro ? 'false' : 'true'}, [icon('table'), el('span', {text: t})]);
			b.disabled = ro;
			b.addEventListener('click', function () { addTable(t); });
			b.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/x-sqb', t); });
			tableList.appendChild(b);
		});
	}
	tableSearch.addEventListener('input', renderTableList);
	sysToggle.addEventListener('change', renderTableList);
	board.addEventListener('dragover', function (e) { if (Array.prototype.indexOf.call(e.dataTransfer.types, 'text/x-sqb') >= 0) e.preventDefault(); });
	board.addEventListener('drop', function (e) {
		var t = e.dataTransfer.getData('text/x-sqb');
		if (!t) return;
		e.preventDefault();
		var r = board.getBoundingClientRect();
		addTable(t, {left: Math.max(0, Math.round(e.clientX - r.left)), top: Math.max(0, Math.round(e.clientY - r.top))});
	});

	// ----------------------------------------------------------------- model changes
	function addTable(name, pos) {
		if (ro || !schema[name]) return;
		var n = state.tables.length;
		var alias = '';
		if (state.tables.some(function (t) { return t.tablename === name; })) {        // the same table twice needs an alias
			var i = 2;
			while (state.tables.some(function (t) { return t.alias === name + i; })) i++;
			alias = name + i;
		}
		state.tables.push({id: uid(), tablename: name, alias: alias, checkall: false, checkboxes: schema[name].map(function () { return false; }),
			position: pos || {top: 20 + 26 * (n % 8), left: 20 + 240 * Math.floor(n / 8) + 30 * (n % 8)}});
		changed();
	}
	function removeTable(id) {
		state.tables = state.tables.filter(function (t) { return t.id !== id; });
		Object.keys(state.joins).forEach(function (k) { if (k.split('--').some(function (f) { return f.split('_')[2] === id; })) delete state.joins[k]; });
		changed();
	}
	function changed() {
		touched = true;
		render();
		sync();
		if (form) form.dispatchEvent(new Event('input', {bubbles: true}));        // red Save
	}
	function boxOf(id) { return state.tables.filter(function (t) { return t.id === id; })[0]; }
	function name(t) { return t.alias === '' ? t.tablename : t.alias; }
	function fromAlias(t) { return t.alias === '' || t.alias === t.tablename ? t.tablename : t.tablename + ' AS ' + t.alias; }

	// ----------------------------------------------------------------- render boxes and joins
	function render() {
		Array.prototype.slice.call(board.querySelectorAll('.sqbBox')).forEach(function (b) { b.remove(); });
		var maxX = 900, maxY = 420;
		state.tables.forEach(function (t) {
			var cols = schema[t.tablename] || [];
			var alias = el('input', {type: 'text', 'class': 'form-control form-control-sm sqbAlias', value: t.alias, placeholder: M.alias, 'aria-label': M.alias, spellcheck: 'false'});
			alias.disabled = ro;
			alias.addEventListener('change', function () { t.alias = alias.value.replace(/[^\w$]/g, ''); changed(); });
			var all = el('input', {type: 'checkbox', 'class': 'form-check-input', title: M.all, 'aria-label': M.all});
			all.checked = t.checkall;
			all.disabled = ro;
			all.addEventListener('change', function () { t.checkall = all.checked; if (all.checked) t.checkboxes = cols.map(function () { return false; }); changed(); });
			var close = el('button', {type: 'button', 'class': 'btn btn-sm btn-link p-0 text-danger', title: M.remove, 'aria-label': M.remove}, [icon('x-lg')]);
			close.disabled = ro;
			close.addEventListener('click', function () { removeTable(t.id); });
			var head = el('div', {'class': 'sqbBoxHead'}, [all, el('span', {'class': 'sqbBoxName', text: t.tablename}), alias, close]);
			var list = el('div', {'class': 'sqbCols'});
			cols.forEach(function (c, i) {
				var cb = el('input', {type: 'checkbox', 'class': 'form-check-input', 'aria-label': t.tablename + '.' + c});
				cb.checked = !!t.checkboxes[i];
				cb.disabled = ro;
				cb.addEventListener('change', function () { t.checkboxes[i] = cb.checked; if (cb.checked) t.checkall = false; changed(); });
				var fid = 'field_' + i + '_' + t.id;
				var label = el('span', {'class': 'sqbColName', id: fid, 'data-field': fid, text: c, title: M.drag_hint});
				list.appendChild(el('div', {'class': 'sqbCol', 'data-field': fid}, [cb, label]));
			});
			var box = el('div', {'class': 'sqbBox', 'data-id': t.id}, [head, list]);
			box.style.left = t.position.left + 'px';
			box.style.top = t.position.top + 'px';
			board.appendChild(box);
			maxX = Math.max(maxX, t.position.left + 260);
			maxY = Math.max(maxY, t.position.top + 60 + cols.length * 22);
			if (!ro) {
				head.addEventListener('pointerdown', function (e) { if (e.target === head || e.target.classList.contains('sqbBoxName')) startMoveBox(e, t, box); });
				list.addEventListener('pointerdown', function (e) { var f = e.target.closest('.sqbColName'); if (f) startJoin(e, f.getAttribute('data-field')); });
				list.addEventListener('scroll', drawJoins);
			}
		});
		board.style.width = maxX + 'px';
		board.style.height = maxY + 'px';
		drawJoins();
		renderClauses();
	}

	function anchor(fid, towards) {
		var node = board.querySelector('.sqbCol[data-field="' + fid + '"]');
		if (!node) return null;
		var box = node.closest('.sqbBox');
		var list = node.parentNode;
		var br = board.getBoundingClientRect(), nr = node.getBoundingClientRect(), xr = box.getBoundingClientRect(), lr = list.getBoundingClientRect();
		var y = Math.min(Math.max(nr.top + nr.height / 2, lr.top + 4), lr.bottom - 4) - br.top;
		var rightSide = towards === undefined ? true : towards > xr.left + xr.width / 2 - br.left;
		return {x: (rightSide ? xr.right : xr.left) - br.left, y: y, mid: xr.left + xr.width / 2 - br.left};
	}
	function drawJoins() {
		while (svg.firstChild) svg.removeChild(svg.firstChild);
		Array.prototype.slice.call(board.querySelectorAll('.sqbJoinTag')).forEach(function (b) { b.remove(); });
		Object.keys(state.joins).forEach(function (k) {
			var p = k.split('--');
			var a0 = anchor(p[0]), b0 = anchor(p[1]);
			if (!a0 || !b0) return;
			var a = anchor(p[0], b0.mid), b = anchor(p[1], a0.mid);
			var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
			var dx = Math.max(40, Math.abs(b.x - a.x) / 2);
			var c1 = a.x + (a.x < b.x ? dx : -dx), c2 = b.x + (a.x < b.x ? -dx : dx);
			path.setAttribute('d', 'M' + a.x + ' ' + a.y + ' C' + c1 + ' ' + a.y + ' ' + c2 + ' ' + b.y + ' ' + b.x + ' ' + b.y);
			path.setAttribute('class', 'sqbLine' + (state.joins[k] === 'LEFT' ? ' sqbLineLeft' : ''));
			svg.appendChild(path);
			var tag = el('span', {'class': 'sqbJoinTag' + (state.joins[k] === 'LEFT' ? ' sqbJoinLeft' : '')});
			var tb = el('button', {type: 'button', 'class': 'btn btn-sm sqbJoinBtn', title: M.join_toggle, text: state.joins[k] === 'LEFT' ? M.left_join : M.join});
			var del = el('button', {type: 'button', 'class': 'btn btn-sm sqbJoinDel', title: M.join_delete, 'aria-label': M.join_delete}, [icon('x')]);
			tb.disabled = del.disabled = ro;
			tb.addEventListener('click', function () { state.joins[k] = state.joins[k] === 'LEFT' ? '' : 'LEFT'; changed(); });
			del.addEventListener('click', function () { delete state.joins[k]; changed(); });
			tag.appendChild(tb);
			tag.appendChild(del);
			tag.style.left = ((a.x + b.x) / 2) + 'px';
			tag.style.top = ((a.y + b.y) / 2) + 'px';
			board.appendChild(tag);
		});
		var r = board.getBoundingClientRect();
		svg.setAttribute('width', String(r.width));
		svg.setAttribute('height', String(r.height));
	}

	function startMoveBox(e, t, box) {
		if (e.button !== 0) return;
		e.preventDefault();
		var sx = e.clientX, sy = e.clientY, l0 = t.position.left, t0 = t.position.top;
		function mv(ev) {
			t.position.left = Math.max(0, l0 + ev.clientX - sx);
			t.position.top = Math.max(0, t0 + ev.clientY - sy);
			box.style.left = t.position.left + 'px';
			box.style.top = t.position.top + 'px';
			drawJoins();
		}
		function up() { document.removeEventListener('pointermove', mv); document.removeEventListener('pointerup', up); changed(); }
		document.addEventListener('pointermove', mv);
		document.addEventListener('pointerup', up);
	}

	/** Drag from a column to a column of another table → join (nuBuilder's key: from--to). */
	function startJoin(e, from) {
		if (e.button !== 0) return;
		e.preventDefault();
		var br = board.getBoundingClientRect();
		var a = anchor(from);
		var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
		line.setAttribute('class', 'sqbLine sqbLineDraft');
		line.setAttribute('x1', String(a.x)); line.setAttribute('y1', String(a.y)); line.setAttribute('x2', String(a.x)); line.setAttribute('y2', String(a.y));
		svg.appendChild(line);
		function target(ev) {
			var n = document.elementFromPoint(ev.clientX, ev.clientY);
			var col = n && n.closest ? n.closest('.sqbCol') : null;
			return col && col.getAttribute('data-field').split('_')[2] !== from.split('_')[2] ? col : null;
		}
		function mv(ev) {
			line.setAttribute('x2', String(ev.clientX - br.left));
			line.setAttribute('y2', String(ev.clientY - br.top));
			Array.prototype.forEach.call(board.querySelectorAll('.sqbCol.sqbTarget'), function (c) { c.classList.remove('sqbTarget'); });
			var t = target(ev);
			if (t) t.classList.add('sqbTarget');
		}
		function up(ev) {
			document.removeEventListener('pointermove', mv);
			document.removeEventListener('pointerup', up);
			line.remove();
			var t = target(ev);
			Array.prototype.forEach.call(board.querySelectorAll('.sqbCol.sqbTarget'), function (c) { c.classList.remove('sqbTarget'); });
			if (!t) return;
			var to = t.getAttribute('data-field');
			if (state.joins[from + '--' + to] === undefined && state.joins[to + '--' + from] === undefined) state.joins[from + '--' + to] = '';
			changed();
		}
		document.addEventListener('pointermove', mv);
		document.addEventListener('pointerup', up);
	}
	window.addEventListener('resize', drawJoins);

	// ----------------------------------------------------------------- clauses
	function selectedFields() {
		var out = [];
		state.tables.forEach(function (t) { (schema[t.tablename] || []).forEach(function (c) { out.push(name(t) + '.' + c); }); });
		return out;
	}
	function renderClauses() {
		clauseBox.innerHTML = '';
		var dl = el('datalist', {id: 'sqbFieldList'});
		selectedFields().forEach(function (f) { dl.appendChild(el('option', {value: f})); });
		var table = el('table', {'class': 'table table-sm align-middle mb-1'}, [el('thead', {}, [el('tr', {}, [el('th', {text: M.type}), el('th', {text: M.field}), el('th', {text: M.clause}),
			el('th', {text: M.sort}), el('th', {text: M.order}), el('th', {})])])]);
		var tb = el('tbody');
		state.clauses.forEach(function (c, i) {
			var type = el('select', {'class': 'form-select form-select-sm', 'aria-label': M.type});
			[['1', 'WHERE'], ['2', 'GROUP BY'], ['3', 'ORDER BY'], ['4', 'HAVING']].forEach(function (o) { type.appendChild(el('option', {value: o[0], text: o[1], selected: o[0] === c.type})); });
			var field = el('input', {type: 'text', 'class': 'form-control form-control-sm font-monospace', list: 'sqbFieldList', value: c.field, 'aria-label': M.field, spellcheck: 'false'});
			var clause = el('input', {type: 'text', 'class': 'form-control form-control-sm font-monospace', value: c.clause, 'aria-label': M.clause, placeholder: c.type === '1' || c.type === '4' ? "= '#cus_country#'" : '', spellcheck: 'false'});
			var sort = el('select', {'class': 'form-select form-select-sm', 'aria-label': M.sort});
			[['', ''], ['ASC', 'ASC'], ['DESC', 'DESC']].forEach(function (o) { sort.appendChild(el('option', {value: o[0], text: o[1], selected: o[0] === c.sort})); });
			var order = el('input', {type: 'number', 'class': 'form-control form-control-sm', value: c.order, 'aria-label': M.order});
			var del = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger', title: M.remove, 'aria-label': M.remove}, [icon('trash')]);
			clause.disabled = c.type === '2' || c.type === '3';
			sort.disabled = c.type === '1' || c.type === '4';
			[type, field, clause, sort, order, del].forEach(function (x) { if (ro) x.disabled = true; });
			type.addEventListener('change', function () { c.type = type.value; changed(); });
			[[field, 'field'], [clause, 'clause'], [sort, 'sort'], [order, 'order']].forEach(function (p) {
				p[0].addEventListener('input', function () { c[p[1]] = p[0].value; touched = true; sync(); });
				p[0].addEventListener('change', function () { c[p[1]] = p[0].value; touched = true; sync(); });
			});
			del.addEventListener('click', function () { state.clauses.splice(i, 1); changed(); });
			tb.appendChild(el('tr', {}, [el('td', {'class': 'sqbClType'}, [type]), el('td', {}, [field]), el('td', {}, [clause]), el('td', {'class': 'sqbClSort'}, [sort]), el('td', {'class': 'sqbClOrder'}, [order]), el('td', {}, [del])]));
		});
		table.appendChild(tb);
		var add = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary'}, [icon('plus-lg'), ' ' + M.clause_add]);
		add.disabled = ro;
		add.addEventListener('click', function () { state.clauses.push({type: '1', field: '', clause: '', sort: '', order: String((state.clauses.length + 1) * 10)}); changed(); });
		clauseBox.appendChild(el('div', {'class': 'fw-semibold small mb-1 mt-2', text: M.clauses}));
		clauseBox.appendChild(dl);
		clauseBox.appendChild(el('div', {'class': 'table-responsive'}, [table]));
		clauseBox.appendChild(add);
	}

	// ----------------------------------------------------------------- SQL (as nuBuilder's nuSelectObject.buildSQL)
	function buildSQL() {
		var cols = [];
		state.tables.forEach(function (t) {
			var n = name(t);
			if (t.checkall) { cols.push(n + '.*'); return; }
			(schema[t.tablename] || []).forEach(function (c, i) {
				if (!t.checkboxes[i]) return;
				cols.push(t.alias === '' ? n + '.' + c : n + '.' + c + ' AS ' + n + '_' + c);
			});
		});
		var sql = 'SELECT\n ' + (cols.length ? cols.join(',\n    ') : '*') + '\n';
		// joins grouped by table pair (several columns → AND)
		var pairs = {};
		Object.keys(state.joins).forEach(function (k) {
			var p = k.split('--'), fa = p[0].split('_'), fb = p[1].split('_');
			var A = boxOf(fa.slice(2).join('_')), B = boxOf(fb.slice(2).join('_'));
			if (!A || !B) return;
			var ca = (schema[A.tablename] || [])[+fa[1]], cb = (schema[B.tablename] || [])[+fb[1]];
			if (ca === undefined || cb === undefined) return;
			var key = [A.id, B.id].sort().join('|');
			pairs[key] = pairs[key] || {a: A, b: B, conds: [], left: false};
			pairs[key].conds.push(name(A) + '.' + ca + ' = ' + name(B) + '.' + cb);
			if (state.joins[k] === 'LEFT') pairs[key].left = true;
		});
		var edges = Object.keys(pairs).map(function (k) { return pairs[k]; });
		var count = {};
		edges.forEach(function (e) { count[e.a.id] = (count[e.a.id] || 0) + 1; count[e.b.id] = (count[e.b.id] || 0) + 1; });
		var placed = {}, comps = [], extra = [];
		var order = state.tables.slice().sort(function (x, y) { return (count[y.id] || 0) - (count[x.id] || 0); });
		order.forEach(function (start) {
			if (placed[start.id]) return;
			placed[start.id] = true;
			var comp = {start: start, joins: []}, progress = true;
			while (progress) {
				progress = false;
				edges.forEach(function (e) {
					if (e.done) return;
					var ina = placed[e.a.id], inb = placed[e.b.id];
					if (!ina && !inb) return;
					e.done = true;
					if (ina && inb) {                                   // a second path between joined tables: more ON conditions
						if (comp.joins.length) comp.joins[comp.joins.length - 1].conds.push.apply(comp.joins[comp.joins.length - 1].conds, e.conds);
						else extra.push.apply(extra, e.conds);
						return;
					}
					var add = ina ? e.b : e.a;
					placed[add.id] = true;
					comp.joins.push({table: add, left: e.left, conds: e.conds.slice()});
					progress = true;
				});
			}
			comps.push(comp);
		});
		var from = comps.map(function (c) {
			return '\n    ' + fromAlias(c.start) + c.joins.map(function (j) { return '\n        ' + (j.left ? 'LEFT JOIN ' : 'JOIN ') + fromAlias(j.table) + ' ON ' + j.conds.join(' AND '); }).join('');
		}).join(',');
		sql += '\nFROM' + (state.tables.length ? from : '\n    ');
		// clauses
		var list = state.clauses.slice().sort(function (a, b) { return (+a.type - +b.type) || ((+a.order || 0) - (+b.order || 0)); });
		var where = extra.map(function (x) { return '(' + x + ')'; }), group = [], having = [], orderBy = [];
		list.forEach(function (c) {
			if (c.field === '') return;
			if (c.type === '1' && c.clause !== '') where.push('(' + c.field + ' ' + c.clause + ')');
			if (c.type === '4' && c.clause !== '') having.push('(' + c.field + c.clause + ')');
			if (c.type === '2') group.push((c.field + ' ' + c.sort).trim());
			if (c.type === '3') orderBy.push((c.field + ' ' + c.sort).trim());
		});
		var cl = '';
		if (where.length) cl += '\n\nWHERE\n    (' + where.join(' AND \n    ') + ')\n';
		if (group.length) cl += '\nGROUP BY\n    ' + group.join(',\n    ') + '\n';
		if (having.length) cl += '\nHAVING\n    ' + having.join(' AND \n    ') + '\n';
		if (orderBy.length) cl += '\nORDER BY\n    ' + orderBy.join(',\n    ') + '\n';
		return sql + cl + '\n';
	}
	function buildJSON() {
		return JSON.stringify({tables: state.tables.map(function (t) {
			return {id: t.id, position: {top: t.position.top, left: t.position.left}, tablename: t.tablename, alias: t.alias, checkall: t.checkall,
				checkboxes: (schema[t.tablename] || []).map(function (c, i) { return !!t.checkboxes[i]; })};
		}), joins: state.joins});
	}
	// The stored SQL stays as it is until something in the builder changes – as nuBuilder, so a save without a change does not
	// rewrite sse_sql (blank lines, order of the generator; 0.8.364, dev/tests/compat.js meta:sqlbuilder)
	var touched = sqlInput.value.trim() === '';
	function sync() {
		jsonInput.value = buildJSON();
		clausesInput.value = JSON.stringify(state.clauses.map(function (c) { return {ssc_type: c.type, ssc_field: c.field, ssc_clause: c.clause, ssc_sort: c.sort, ssc_order: c.order}; }));
		if (!manual.checked && touched) sqlInput.value = state.tables.length ? buildSQL() : sqlInput.value;
		sqlInput.readOnly = !manual.checked || ro;
	}
	manual.addEventListener('change', function () { touched = true; if (!manual.checked) sqlInput.value = buildSQL(); sync(); });

	// ----------------------------------------------------------------- preview
	var result = document.getElementById('sqbResult');
	var pbtn = document.getElementById('sqbPreview');
	if (pbtn) pbtn.addEventListener('click', function () {
		var fd = new FormData();
		fd.append('action', 'preview');
		fd.append('_csrf', D.csrf);
		fd.append('sql', sqlInput.value);
		result.innerHTML = '<div class="small text-body-secondary"><span class="spinner-border spinner-border-sm me-1"></span>…</div>';
		fetch(D.url, {method: 'POST', credentials: 'same-origin', body: fd, headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); }).then(function (r) {
			result.innerHTML = '';
			if (!r.ok) { result.appendChild(el('div', {'class': 'alert alert-danger small', text: M.run_failed + ' ' + (r.message || '')})); return; }
			var t = el('table', {'class': 'table table-sm table-striped table-bordered mb-0 small'});
			t.appendChild(el('thead', {}, [el('tr', {}, r.columns.map(function (c) { return el('th', {text: c}); }))]));
			var tb = el('tbody');
			r.rows.forEach(function (row) { tb.appendChild(el('tr', {}, row.map(function (v) { return el('td', {'class': v === null ? 'text-body-tertiary' : '', text: v === null ? 'NULL' : v}); }))); });
			t.appendChild(tb);
			result.appendChild(el('div', {'class': 'small fw-semibold mb-1', text: (M.rows || '%d').replace('%d', r.rows.length) + (r.more ? ' ' + M.more : '')}));
			result.appendChild(r.rows.length ? el('div', {'class': 'table-responsive sqbResultTable'}, [t]) : el('div', {'class': 'text-body-secondary small', text: M.no_rows}));
		}).catch(function (e) { result.innerHTML = ''; result.appendChild(el('div', {'class': 'alert alert-danger small', text: M.run_failed + ' ' + e})); });
	});

	renderTableList();
	render();
	sync();
	if (form) form.addEventListener('submit', sync);
})();
