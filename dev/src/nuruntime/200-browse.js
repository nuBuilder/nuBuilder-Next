	// =================================================================== browse
	// as nuBuilder Forte 4.9: columns = numbers (column index) and / or strings (column id, data-nu-column-id), none / [] = every column;
	// each cell gets row, column, columnId and the properties text / html; the values the callback returns are collected
	api('nuBrowseLoop', function (cols, fn) {
		var list = Array.isArray(cols) ? cols : (cols === undefined || cols === null || cols === '' ? [] : [cols]);
		var out = [];
		document.querySelectorAll('#nuBrowseTable div.nuCell[data-nu-column]').forEach(function (cell) {
			var col = +cell.getAttribute('data-nu-column'), cid = cell.getAttribute('data-nu-column-id') || '';
			if (list.length && !list.some(function (c) { return typeof c === 'number' ? c === col : String(c) === cid || (/^\d+$/.test(String(c)) && +c === col && !cid); })) return;
			cell.row = parseInt(cell.getAttribute('data-nu-row'), 10);
			cell.column = col;
			cell.columnId = cid;
			if (!Object.prototype.hasOwnProperty.call(cell, 'text')) {
				Object.defineProperty(cell, 'text', {configurable: true, get: function () { return this.textContent; }, set: function (v) { this.textContent = v; }});
				Object.defineProperty(cell, 'html', {configurable: true, get: function () { return this.innerHTML; }, set: function (v) { this.innerHTML = v; }});
			}
			var r = fn(cell);
			if (r !== undefined) out.push(r);
		});
		return out;
	});
	// column widths by dragging the title (as nuBuilder Forte) – window.nuColResize of assets/js/nuapp.js (also the system lists):
	// kept for this browser tab per form and lookup (sessionStorage), restored after nuOnLoad (the widths of this tab win over the form's code);
	// the cells of a column = its data-nu-column elements (the browse cells have a max-width of their own); nuDisableBrowseResize() switches it off
	var BR = {ctl: null, off: false};
	function brTable() { return document.getElementById('nuBrowseTable'); }
	// the title and the table cells of a column (the Forte-style div.nuCell sits inside the td)
	function brColCells(t, c) {
		var out = Array.prototype.slice.call(t.querySelectorAll('thead th[data-nu-col="' + c + '"]'));
		t.querySelectorAll('tbody div.nuCell[data-nu-column="' + c + '"]').forEach(function (d) { out.push(d.parentNode); });
		return out;
	}
	function brInit() {
		var t = brTable();
		if (BR.ctl || !t || N.mode !== 'browse' || typeof window.nuColResize !== 'function') return BR.ctl;
		if (document.body.classList.contains('nuPrintView')) return null;   // the print view keeps its own widths (no dragged widths of the list)
		BR.ctl = window.nuColResize(t, {key: 'nunext-browse-w:' + N.form.id + (N.lookup ? ':lookup' : ''), titles: 'thead th.nuBrowseTitle',
			id: function (th) { return th.getAttribute('data-nu-col'); }, cells: function (c) { return brColCells(t, c); },
			fixedClass: 'nuBrowseFixed', saved: N.lookup ? null : brCookie});
		if (BR.ctl && BR.off) BR.ctl.off();
		return BR.ctl;
	}
	// the dragged widths also in a session cookie: the server draws the list with them, so a new page does not show the stored
	// widths first and jump to the dragged ones when the script runs; {form id: {column: px}}, the 12 lists used last
	function brCookie(o) {
		var all = {};
		try { var m = document.cookie.match(/(?:^|;\s*)nunext_bw=([^;]*)/); if (m) all = JSON.parse(decodeURIComponent(m[1])) || {}; } catch (x) { all = {}; }
		delete all[N.form.id];
		all[N.form.id] = o;
		var ks = Object.keys(all);
		while (ks.length > 12) delete all[ks.shift()];
		var v = encodeURIComponent(JSON.stringify(all));
		if (v.length > 3500) return;
		document.cookie = 'nunext_bw=' + v + '; path=' + location.pathname.replace(/[^/]*$/, '') + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	}
	function brRestore() { if (brInit() && !BR.off) BR.ctl.restore(); }
	$(brRestore);                                                       // dragged widths before the first paint, again after nuOnLoad
	api('nuDisableBrowseResize', function () {
		BR.off = true;
		if (BR.ctl) BR.ctl.off();
	});
	api('nuSetBrowseColumnSize', function (c, w) {
		var t = brTable(), cells = $(t ? brColCells(t, c) : []);
		if (+w === 0) cells.hide(); else { cells.show().css('width', w + 'px'); if (BR.ctl) BR.ctl.set(String(c), w); }
		if (BR.ctl) BR.ctl.fit();
	});

	function openRow(tr, newTab) {
		var pk = tr.getAttribute('data-nu-primary-key');
		if (N.lookup) {
			try { window.parent.nuNextLookupChosen(N.lookup, pk); } catch (x) { reportError(x.message, 'lookup'); }
			return;
		}
		if (N.form.type === 'browse' && !N.form.redirect) return;
		if (N.rights && N.rights.edit === false && !N.form.redirect) return;   // access level "browse only": rows open nothing
		// nuBuilder Forte's "Target" of the list: 1 current breadcrumb (replaces the list), 2 new tab, 3 popup; 0 = as before
		var tg = N.iframe || newTab ? '0' : String(N.form.target || '0');
		if (tg === '3' && g('nuPopupModal')) { nuPopup(N.form.id, pk); return; }
		if (tg === '2') newTab = true;
		go(function () { return formUrl(N.form.id, pk, N.iframe ? {iframe: '1'} : (!newTab && tg !== '1' && N.bt && N.bt.child ? {bt: N.bt.child} : {})); },
			newTab ? 'blank' : (tg === '1' ? function (url) { window.location.replace(url); } : null));
	}

	$(document).on('click', 'tr.nuBrowseRow', function (e) {
		// the form takes over row clicks – with the clicked cell as element, as nuBuilder (not an element inside it)
		if (window.nuSelectBrowse) { window.nuSelectBrowse(e, (e.target.closest && e.target.closest('div.nuCell')) || this); return; }
		if (e.target.closest && e.target.closest('.nuSelCol')) return;        // the row's checkbox (totals of selected rows)
		if (window.getSelection && String(window.getSelection()).length) return;
		openRow(this, e.ctrlKey || e.metaKey);
	});
	// totals of selected rows: lists with a row of totals have a checkbox column; while rows of the page are ticked, the row of totals
	// shows the values of those rows (worked out here from the unformatted values, in the column's format) – none ticked = all matching
	(function () {
		function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; }
		function plain(n) { var s = (Math.round(n * 10000) / 10000).toString(); return s === '-0' ? '0' : s; }
		function selTotals() {
			var B = N.browse || {}, T = B.totals || {}, F = B.formats || {}, raw = B.rows || [], foot = document.getElementById('nuBrowseTotals');
			if (!foot) return;
			var boxes = document.querySelectorAll('#nuRECORD tr.nuBrowseRow .nuRowSel'), sel = [];
			boxes.forEach(function (b) { var tr = b.closest('tr'); tr.classList.toggle('table-active', b.checked); if (b.checked) sel.push(raw[+tr.getAttribute('data-nu-row')] || []); });
			var all = document.getElementById('nuRowSelAll');
			if (all) { all.checked = boxes.length > 0 && sel.length === boxes.length; all.indeterminate = sel.length > 0 && sel.length < boxes.length; }
			var clr = document.getElementById('nuSelClear');
			if (clr) { clr.hidden = !sel.length; clr.textContent = sel.length ? String(clr.getAttribute('data-nu-text') || '%d').replace('%d', String(sel.length)) : ''; }
			foot.classList.toggle('nuTotalSel', sel.length > 0);
			Object.keys(T).forEach(function (i) {
				var cell = foot.querySelector('td[data-nu-column="' + i + '"] .nuTotalVal');
				if (!cell) return;
				if (!cell.hasAttribute('data-nu-all')) cell.setAttribute('data-nu-all', cell.textContent);
				if (!sel.length) { cell.textContent = cell.getAttribute('data-nu-all'); return; }
				var fn = T[i].fn, fmt = String(F[i] || ''), isNum = /^N\|/i.test(fmt), isDate = /^D\|/i.test(fmt);
				var vals = sel.map(function (r) { return r[+i + 1]; }).filter(function (v) { return v !== null && v !== undefined && v !== ''; });
				var out = '';
				if (fn === 'count') out = String(vals.length);
				else if (!vals.length || (isDate && (fn === 'sum' || fn === 'avg'))) out = '';
				else {
					var v;
					if (fn === 'sum' || fn === 'avg') { v = vals.reduce(function (a, x) { return a + num(x); }, 0); if (fn === 'avg') v = v / vals.length; v = plain(v); }
					else {
						var numeric = isNum || (!isDate && vals.every(function (x) { return String(x).trim() !== '' && isFinite(Number(x)); }));
						v = vals.reduce(function (a, x) { var less = numeric ? num(x) < num(a) : String(x) < String(a); return (fn === 'min') === less ? x : a; });
						v = String(v);
					}
					try { out = fmt && window.NuFormatLib ? NuFormatLib.add(v, fmt) : v; } catch (e) { out = v; }
				}
				cell.textContent = out;
			});
		}
		$(document).on('change', '#nuRECORD .nuRowSel', selTotals);
		$(document).on('change', '#nuRowSelAll',
			function () { var on = this.checked; document.querySelectorAll('#nuRECORD tr.nuBrowseRow .nuRowSel').forEach(function (b) { b.checked = on; }); selTotals(); });
		$(document).on('click', '#nuSelClear', function () { document.querySelectorAll('#nuRECORD .nuRowSel').forEach(function (b) { b.checked = false; }); selTotals(); });
		N.selectedTotals = selTotals;
	})();
	$(document).on('keydown', 'tr.nuBrowseRow', function (e) { if (e.key === 'Enter' && !e.target.closest('.nuRowDel, .nuCellAct')) openRow(this, e.ctrlKey || e.metaKey); });
	// row details (core/form/nurowdetails.php): the ▸ of a row folds a strip open below it – the row's hidden (width 0)
	// columns and cut texts (read from the row itself), content of the form's code (nuNextRowDetails), and as tabs: a detail form shown
	// read only in a frame, and related lists (sub-forms, child tables; formapi action "details" {list}, each loaded once per row).
	// One open row at a time or many (setting mode); the open rows are kept while paging (sessionStorage); Expand all / Collapse all in the
	// title of the ▸ column. Hooks: nuOnRowExpand(pk, stripElement) / nuOnRowCollapse(pk)
	var RD = {own: null, key: 'nunext-rd:' + ((N.form && N.form.id) || '')};
	api('nuNextRowDetails', function (fn) { RD.own = typeof fn === 'function' ? fn : null; });
	function rdState() { try { return JSON.parse(sessionStorage.getItem(RD.key) || '{}') || {}; } catch (e) { return {}; } }
	function rdSave(st) { nuStore.session.set(RD.key, JSON.stringify(st)); }
	function rdRemember(pk, on) {
		var st = rdState(), open = st.open || [];
		var i = open.indexOf(pk);
		if (on && i < 0) open.push(pk); else if (!on && i >= 0) open.splice(i, 1);
		if (!on) st.all = false;
		st.open = open.slice(-200);
		rdSave(st);
	}
	function detClose(tr, quiet) {
		var n = tr.nextElementSibling;
		if (n && n.classList.contains('nuDetRow')) n.remove();
		var b = tr.querySelector('.nuDetBtn');
		if (b) { b.setAttribute('aria-expanded', 'false'); b.title = M.rd_show; b.setAttribute('aria-label', M.rd_show); }
		if (!tr.classList.contains('nuDetOpen')) return;
		tr.classList.remove('nuDetOpen');
		var pk = tr.getAttribute('data-nu-primary-key') || '';
		if (!quiet) rdRemember(pk, false);
		callHook('nuOnRowCollapse', pk);
		rdAllSync();
	}
	function detCols(tr) {
		var dl = document.createElement('dl');
		dl.className = 'nuDetCols';
		tr.querySelectorAll('td.nuCellTd').forEach(function (td) {
			var div = td.querySelector('div.nuCell'), label = td.getAttribute('data-nu-label') || '';
			if (!div || !label) return;
			var txt = (td.style.display === 'none' ? div.textContent : (div.innerText || div.textContent) || '').trim();
			if (!txt) return;
			var hidden = td.style.display === 'none';
			if (!hidden && !(div.scrollWidth > div.clientWidth + 1 || div.scrollHeight > div.clientHeight + 1)) return;
			var dt = document.createElement('dt'), dd = document.createElement('dd');
			dt.textContent = label; dd.textContent = txt;
			dl.appendChild(dt); dl.appendChild(dd);
		});
		return dl.children.length ? dl : null;
	}
	function detRelated(box, res) {
		box.textContent = '';
		if (!res || !res.ok) { box.textContent = M.rd_error; return; }
		if (!res.title) { box.textContent = M.rd_empty; return; }
		var h = document.createElement('div');
		h.className = 'nuDetRelHead';
		h.textContent = res.title + ' (' + res.total + ')';
		box.appendChild(h);
		if (!res.rows.length) { var p = document.createElement('div'); p.className = 'text-body-secondary small'; p.textContent = M.rd_none_rows; box.appendChild(p); return; }
		var tbl = document.createElement('table'), th = document.createElement('thead'), hr = document.createElement('tr'), tb = document.createElement('tbody');
		tbl.className = 'table table-sm mb-1 nuDetTable';
		res.cols.forEach(function (c, i) { var x = document.createElement('th'); x.scope = 'col'; x.textContent = c; if (res.align[i] === 'r') x.className = 'text-end'; hr.appendChild(x); });
		th.appendChild(hr);
		res.rows.forEach(function (r) {
			var row = document.createElement('tr');
			r.forEach(function (v, i) { var td = document.createElement('td'); td.textContent = v; if (res.align[i] === 'r') td.className = 'text-end'; row.appendChild(td); });
			tb.appendChild(row);
		});
		tbl.appendChild(th); tbl.appendChild(tb);
		var wrap = document.createElement('div');
		wrap.className = 'table-responsive';
		wrap.appendChild(tbl);
		box.appendChild(wrap);
		if (res.more) { var m = document.createElement('div'); m.className = 'small text-body-secondary'; m.textContent = M.rd_more.replace('%d', String(res.total - res.rows.length)); box.appendChild(m); }
	}
	// the detail form: read only in a frame (?rdv=1), as high as its content (same origin – its body is measured)
	function detFrame(box, url, title) {
		var f = document.createElement('iframe');
		f.className = 'nuDetFrame';
		f.title = title || M.rd_form;
		f.setAttribute('loading', 'lazy');
		var fit = function () {
			try {
				var d = f.contentDocument, m = d && (d.getElementById('main') || d.body);
				if (m) f.style.height = Math.min(1200, Math.max(120, Math.ceil(m.scrollHeight) + 8)) + 'px';
			} catch (e) { /* other origin */ }
		};
		f.addEventListener('load', function () {
			fit();
			try { if (window.ResizeObserver) new ResizeObserver(fit).observe(f.contentDocument.body); } catch (e) { /* ignore */ }
			setTimeout(fit, 400);
		});
		f.src = url;
		box.appendChild(f);
	}
	function detOpen(tr, quiet) {
		var D = (N.browse || {}).details;
		if (!D || tr.classList.contains('nuDetOpen')) return;
		if (D.mode === 'one') document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow.nuDetOpen').forEach(function (o) { if (o !== tr) detClose(o); });
		tr.classList.add('nuDetOpen');
		var pk = tr.getAttribute('data-nu-primary-key') || '';
		if (!quiet) rdRemember(pk, true);
		var b = tr.querySelector('.nuDetBtn');
		if (b) { b.setAttribute('aria-expanded', 'true'); b.title = M.rd_hide; b.setAttribute('aria-label', M.rd_hide); }
		var row = document.createElement('tr'), td = document.createElement('td'), body = document.createElement('div');
		row.className = 'nuDetRow';
		row.setAttribute('data-nu-primary-key', pk);
		td.className = 'nuDetTd';
		td.colSpan = tr.children.length;
		body.className = 'nuDetBody';
		td.appendChild(body); row.appendChild(td);
		tr.parentNode.insertBefore(row, tr.nextSibling);
		var cols = D.cols ? detCols(tr) : null;
		if (cols) body.appendChild(cols);
		if (RD.own) {                                            // the form's own content (nuNextRowDetails(fn))
			var own = document.createElement('div');
			own.className = 'nuDetOwn';
			body.appendChild(own);
			try {
				var ret = RD.own(pk, own, tr);
				var put = function (v) { if (typeof v === 'string' && v !== '') own.innerHTML = v; if (!own.childNodes.length) own.remove(); };
				if (ret && typeof ret.then === 'function') ret.then(put, function (x) { reportError(String(x && x.message || x), 'nuNextRowDetails'); own.remove(); }); else put(ret);
			} catch (x) { reportError(x.message, 'nuNextRowDetails'); own.remove(); }
		}
		// tabs: the detail form, then the related lists
		var parts = [];
		if (D.form) parts.push({key: 'form', title: D.form_title || M.rd_form});
		(D.lists || []).forEach(function (l) { parts.push({key: l.key, title: l.title}); });
		if (parts.length) {
			var wrap = document.createElement('div');
			wrap.className = 'nuDetParts';
			var nav = null;
			if (parts.length > 1) {
				nav = document.createElement('div');
				nav.className = 'nuDetTabs';
				nav.setAttribute('role', 'tablist');
				wrap.appendChild(nav);
			}
			var panes = {};
			var show = function (k) {
				parts.forEach(function (p) {
					var on = p.key === k;
					if (p.btn) { p.btn.classList.toggle('active', on); p.btn.setAttribute('aria-selected', on ? 'true' : 'false'); p.btn.tabIndex = on ? 0 : -1; }
					if (panes[p.key]) panes[p.key].hidden = !on;
				});
				if (!panes[k]) {
					var pane = panes[k] = document.createElement('div');
					pane.className = 'nuDetPane';
					pane.setAttribute('data-nu-det', k);
					if (nav) pane.setAttribute('role', 'tabpanel');
					wrap.appendChild(pane);
					if (k === 'form') detFrame(pane, D.form.replace('__PK__', encodeURIComponent(pk)), D.form_title);
					else {
						pane.classList.add('nuDetRel');
						tr._nuDet = tr._nuDet || {};
						if (tr._nuDet[k]) detRelated(pane, tr._nuDet[k]);
						else {
							pane.textContent = M.rd_loading;
							call('details', {record_id: pk, list: k}).then(function (res) { tr._nuDet[k] = res; detRelated(pane, res); }, function () { pane.textContent = M.rd_error; });
						}
					}
				}
				try { var st = rdState(); st.tab = k; rdSave(st); } catch (e) { /* ignore */ }
			};
			parts.forEach(function (p) {
				if (!nav) return;
				var bt = p.btn = document.createElement('button');
				bt.type = 'button';
				bt.className = 'nuDetTab';
				bt.setAttribute('role', 'tab');
				bt.setAttribute('data-nu-det-tab', p.key);
				bt.textContent = p.title;
				bt.addEventListener('click', function (e) { e.stopPropagation(); show(p.key); });
				bt.addEventListener('keydown', function (e) {
					if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
					e.preventDefault(); e.stopPropagation();
					var i = parts.indexOf(p), j = (i + (e.key === 'ArrowRight' ? 1 : -1) + parts.length) % parts.length;
					show(parts[j].key); parts[j].btn.focus();
				});
				nav.appendChild(bt);
			});
			body.appendChild(wrap);
			var want = rdState().tab;
			show(parts.some(function (p) { return p.key === want; }) ? want : parts[0].key);
		}
		if (!body.children.length) body.textContent = M.rd_empty;
		var open = document.createElement('button');
		open.type = 'button';
		open.className = 'btn btn-sm btn-outline-secondary nuDetOpenRec';
		open.innerHTML = '<i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>';
		open.appendChild(document.createTextNode(M.rd_open));
		open.addEventListener('click', function (e) { e.stopPropagation(); openRow(tr, e.ctrlKey || e.metaKey); });
		td.appendChild(open);
		callHook('nuOnRowExpand', pk, body);
		rdAllSync();
	}
	function detToggle(tr, want) {
		var on = tr.classList.contains('nuDetOpen');
		if (want === undefined) want = !on;
		if (want && !on) detOpen(tr); else if (!want && on) detClose(tr);
	}
	N.rowDetails = detToggle;
	// Expand all / Collapse all (many open rows only): the button in the title of the ▸ column
	function rdRows() { return Array.prototype.slice.call(document.querySelectorAll('#nuBrowseTable > tbody > tr.nuBrowseRow')).filter(function (r) { return r.querySelector('.nuDetBtn'); }); }
	function rdAllSync() {
		var b = document.getElementById('nuDetAll');
		if (!b) return;
		var rows = rdRows(), on = rows.length > 0 && rows.every(function (r) { return r.classList.contains('nuDetOpen'); });
		b.setAttribute('aria-expanded', on ? 'true' : 'false');
		b.title = on ? M.rd_collapse_all : M.rd_expand_all;
		b.setAttribute('aria-label', b.title);
		b.classList.toggle('nuDetAllOn', on);
	}
	function rdAll(on) {
		var st = rdState();
		rdRows().forEach(function (r) { if (on) detOpen(r, true); else detClose(r, true); });
		st.all = on;
		st.open = on ? rdRows().map(function (r) { return r.getAttribute('data-nu-primary-key') || ''; }) : [];
		rdSave(st);
		rdAllSync();
	}
	N.rowDetailsAll = rdAll;
	// open again what was open (also after paging: the rows of the other page that are in the list, or all when Expand all was used)
	function rdRestore() {
		var D = (N.browse || {}).details;
		if (!D) return;
		var th = document.querySelector('#nuBrowseTable > thead th.nuDetCol');
		if (th && D.mode !== 'one' && !document.getElementById('nuDetAll')) {
			th.textContent = '';
			var b = document.createElement('button');
			b.type = 'button'; b.id = 'nuDetAll'; b.className = 'btn btn-sm btn-link nuDetAllBtn';
			b.innerHTML = '<i class="bi bi-chevron-double-right" aria-hidden="true"></i>';
			b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); rdAll(b.getAttribute('aria-expanded') !== 'true'); });
			th.appendChild(b);
		}
		var st = rdState(), rows = rdRows();
		rows.forEach(function (r) {
			var pk = r.getAttribute('data-nu-primary-key') || '';
			if ((st.all && D.mode !== 'one') || (st.open || []).indexOf(pk) >= 0) detOpen(r, true);
		});
		if (D.mode === 'one') { var o = rows.filter(function (r) { return r.classList.contains('nuDetOpen'); }); o.slice(1).forEach(function (r) { detClose(r, true); }); }
		rdAllSync();
	}
	N.rowDetailsRestore = rdRestore;
	document.addEventListener('nu:listrefresh', function () { rdRestore(); });
	$(document).on('click', '#nuBrowseTable .nuDetBtn', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var tr = this.closest('tr.nuBrowseRow');
		if (tr) detToggle(tr);
	});
	$(document).on('click', '#nuBrowseTable tr.nuDetRow', function (e) { e.stopPropagation(); });
	// click action of a cell (design mode → column → On click): instead of the row's edit form – another form, a list in the
	// context of the row, a procedure, a report or a JavaScript function. The row's id goes along as a property (#PARENT_ID# by default)
	$(document).on('click', '#nuBrowseTable .nuCellAct', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var a = ((N.browse || {}).actions || {})[this.getAttribute('data-nu-act')];
		if (!a) return;
		var info = nuBrowseRow(this), pk = info.pk, val = this.getAttribute('data-nu-v');
		info.value = val;
		var ctx = function () {
			if (a.pass) nuSetProperty(a.pass, pk);
			nuSetProperty('PARENT_FORM_ID', N.form.id);
		};
		try {
			if (a.t === 'js') {
				var fn = String(a.fn || '').split('.').reduce(function (o, k) { return o && o[k]; }, window);
				if (typeof fn !== 'function') { reportError('On click: ' + a.fn + ' is not a function', 'browse'); return; }
				fn(Object.assign({}, info, {formId: N.form.id}), e);
				return;
			}
			ctx();
			if (a.t === 'proc') { if (a.hidden) nuRunPHPHidden(a.code); else nuRunPHP(a.code); return; }
			if (a.t === 'report') { nuRunReport(a.code); return; }
			var open = e.ctrlKey || e.metaKey ? '2' : String(a.open || '0');
			var rec = a.t === 'list' ? '' : (a.rec === 'new' ? '-1' : (a.rec === 'value' ? String(val) : pk));
			if (open === '3') nuPopup(a.form, rec); else nuForm(a.form, rec, '', '', open);
		} catch (x) { reportError(x.message, 'browse'); }
	});
	// delete button at the left of a browse row (Notes): asks, deletes through the form API (rights, Before Delete, audit), reloads
	$(document).on('click', '#nuBrowseTable .nuRowDel', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var btn = this, pk = btn.getAttribute('data-nu-pk');
		var go = function () {
			btn.disabled = true;
			call('delete', {record_id: pk}).then(function (res) {
				if (!res.ok) { btn.disabled = false; applyResult(res); return; }
				try { sessionStorage.setItem('nuNextToast', JSON.stringify([M.deleted || 'Record deleted.', 'success', res.recycle ? {undo: res.recycle, t: Date.now()} : null])); } catch (x) { /* ignore */ }
				window.location.reload();
			}, function () { btn.disabled = false; });
		};
		if (typeof window.nuConfirmAsk === 'function') {
			window.nuConfirmAsk(M.delete_confirm, go, M.delete, 'btn btn-danger', {title: M.delete_title, note: M.delete_undo, cancelFirst: true});
		} else if ($.confirm) {
			$.confirm({title: M.delete, content: M.delete_confirm, useBootstrap: false, boxWidth: '360px', type: 'red',
				buttons: {confirm: {text: M.delete, btnClass: 'btn-red', action: go}, cancel: {text: M.cancel}}});
		} else if (window.confirm(M.delete_confirm)) go();
	});

