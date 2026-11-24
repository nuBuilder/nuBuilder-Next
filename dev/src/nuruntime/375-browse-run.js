	// =================================================================== run the browse SQL (option A of the canvas "Run browse SQL")
	// ▶ Run in the toolbar of a browse SQL editor (design mode Code, the list's side pane) – also Ctrl+Enter there: the SQL as written,
	// read only on the server (pages/nubrowserun.php) with the hash variables filled as on the list; a window shows the rows with paging,
	// the list's columns only or all, Copy as CSV, the SQL as run. N.browseRun(sql, cm) – cm: an error moves the cursor to its line.
	(function () {
		var R = N.browse_run;
		if (!R || !window.NuUtil) return;
		var M = R.m || {}, U = window.NuUtil, el = U.el;
		var st = {sql: '', page: 1, per: 10, listOnly: false, data: null, cm: null}, modal = null, bm = null;
		try { st.per = parseInt(localStorage.getItem('nunext-brun-per') || '10', 10) || 10; st.listOnly = localStorage.getItem('nunext-brun-list') === '1'; } catch (x) { /* no storage */ }
		function build() {
			modal = el('div', {'class': 'modal fade nuBrunDlg', id: 'nuBrunDlg', tabindex: '-1', 'aria-labelledby': 'nuBrunTitle', 'aria-hidden': 'true'}, [
				el('div', 'modal-dialog modal-xl modal-dialog-scrollable', [el('div', 'modal-content', [
					el('div', 'modal-header gap-2 flex-wrap', [
						el('h2', {'class': 'modal-title fs-6', id: 'nuBrunTitle', text: M.title}),
						el('span', {'class': 'badge nuBrunInfo', id: 'nuBrunInfo'}),
						el('span', {'class': 'small text-body-secondary', text: M.ro}),
						el('button', {type: 'button', 'class': 'btn-close ms-auto', 'data-bs-dismiss': 'modal', 'aria-label': M.close})]),
					el('div', 'modal-body', [
						el('div', 'd-flex flex-wrap align-items-center gap-3 mb-2 small', [
							el('label', 'form-check form-switch mb-0', [el('input', {type: 'checkbox', 'class': 'form-check-input', id: 'nuBrunList', role: 'switch'}),
								el('span', {'class': 'form-check-label', text: M.list_only})]),
							el('label', 'd-flex align-items-center gap-2 ms-auto', [el('span', {text: M.rows}),
								el('select', {'class': 'form-select form-select-sm w-auto', id: 'nuBrunPer'}, [10, 25, 50, 100].map(function (n) { return el('option', {value: String(n), text: String(n)}); }))])]),
						el('div', {'class': 'alert alert-danger py-2 small', id: 'nuBrunErr', hidden: true}),
						el('div', 'table-responsive nuBrunWrap', [el('table', {'class': 'table table-sm nu-table nuBrunTable mb-0', id: 'nuBrunTable'})]),
						el('div', 'd-flex align-items-center gap-2 mt-2 small', [el('span', {'class': 'text-body-secondary', id: 'nuBrunCount'}), el('span', 'ms-auto'),
							el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuBrunPrev', 'aria-label': M.prev, text: '‹'}),
							el('span', {id: 'nuBrunPage'}),
							el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuBrunNext', 'aria-label': M.next, text: '›'})]),
						el('pre', {'class': 'nuBrunSql small mt-2 mb-0', id: 'nuBrunSql', hidden: true})]),
					el('div', 'modal-footer', [
						el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuBrunCsv', text: M.csv}),
						el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuBrunShowSql', text: M.show_sql}),
						el('button', {type: 'button', 'class': 'btn btn-primary', 'data-bs-dismiss': 'modal', text: M.close})])])])]);
			document.body.appendChild(modal);
			bm = window.nuBs ? window.nuBs.modal(modal) : null;
			var list = modal.querySelector('#nuBrunList'), per = modal.querySelector('#nuBrunPer');
			list.checked = st.listOnly; per.value = String(st.per);
			list.addEventListener('change', function () { st.listOnly = list.checked; try { localStorage.setItem('nunext-brun-list', list.checked ? '1' : '0'); } catch (x) { /* none */ } draw(); });
			per.addEventListener('change', function () { st.per = parseInt(per.value, 10) || 10; st.page = 1; try { localStorage.setItem('nunext-brun-per', String(st.per)); } catch (x) { /* none */ } run(); });
			modal.querySelector('#nuBrunPrev').addEventListener('click', function () { if (st.page > 1) { st.page--; run(); } });
			modal.querySelector('#nuBrunNext').addEventListener('click', function () { st.page++; run(); });
			modal.querySelector('#nuBrunShowSql').addEventListener('click', function () { var p = modal.querySelector('#nuBrunSql'); p.hidden = !p.hidden; });
			modal.querySelector('#nuBrunCsv').addEventListener('click', csv);
			modal.addEventListener('hidden.bs.modal', function () { if (st.cm) st.cm.focus(); });
		}
		function shownCols() {
			var d = st.data;
			if (!d) return [];
			var idx = d.cols.map(function (c, i) { return i; });
			return st.listOnly && d.list.length ? idx.filter(function (i) { return d.list.indexOf(d.cols[i]) >= 0; }) : idx;
		}
		function draw() {
			var d = st.data, tb = modal.querySelector('#nuBrunTable');
			tb.textContent = '';
			if (!d) return;
			var ix = shownCols();
			tb.appendChild(el('thead', '', [el('tr', '', ix.map(function (i) { return el('th', {scope: 'col', text: d.cols[i]}); }))]));
			tb.appendChild(el('tbody', '', d.rows.length ? d.rows.map(function (r) {
				return el('tr', '', ix.map(function (i) { return r[i] === null ? el('td', {'class': 'nuBrunNull', text: 'NULL'}) : el('td', {text: r[i].length > 300 ? r[i].slice(0, 300) + '…' : r[i]}); }));
			}) : [el('tr', '', [el('td', {colspan: String(Math.max(1, ix.length)), 'class': 'text-body-secondary', text: M.none})])]));
			var from = d.rows.length ? (d.page - 1) * d.per + 1 : 0, to = (d.page - 1) * d.per + d.rows.length;
			var pages = d.total === null ? null : Math.max(1, Math.ceil(Math.min(d.total, d.max) / d.per));
			modal.querySelector('#nuBrunCount').textContent = d.total === null ? M.rows_range.replace('%1$s', from).replace('%2$s', to)
				: M.rows_of.replace('%1$s', from).replace('%2$s', to).replace('%3$s', d.total) + (d.capped ? ' · ' + M.capped.replace('%s', d.max) : '');
			modal.querySelector('#nuBrunPage').textContent = pages ? d.page + ' / ' + pages : String(d.page);
			modal.querySelector('#nuBrunPrev').disabled = d.page <= 1;
			modal.querySelector('#nuBrunNext').disabled = pages ? d.page >= pages : d.rows.length < d.per;
			var info = modal.querySelector('#nuBrunInfo');
			info.className = 'badge nuBrunInfo text-bg-success';
			info.textContent = (d.total === null ? '' : M.n_rows.replace('%s', d.total) + ' · ') + d.ms + ' ms';
		}
		function run() {
			var body = new URLSearchParams({f: N.form.id, sql: st.sql, page: String(st.page), per: String(st.per), props: JSON.stringify(N.props || {})});
			modal.classList.add('nuBrunBusy');
			U.post(R.url, body).then(function (d) {
				modal.classList.remove('nuBrunBusy');
				var err = modal.querySelector('#nuBrunErr'), sq = modal.querySelector('#nuBrunSql');
				sq.textContent = d.sql_run || st.sql;
				if (!d || !d.ok) {
					err.hidden = false;
					err.textContent = (d && d.error ? d.error : 'Error') + (d && d.line ? ' (' + M.line.replace('%s', d.line) + ')' : '');
					st.data = null; draw();
					var info = modal.querySelector('#nuBrunInfo'); info.className = 'badge nuBrunInfo text-bg-danger'; info.textContent = M.failed;
					modal.querySelector('#nuBrunCount').textContent = ''; modal.querySelector('#nuBrunPage').textContent = '';
					if (d && d.line && st.cm) st.cm.setCursor({line: d.line - 1, ch: 0});
					return;
				}
				err.hidden = true;
				st.data = d; st.page = d.page;
				draw();
			}).catch(function () { modal.classList.remove('nuBrunBusy'); });
		}
		function csv() {
			var d = st.data;
			if (!d) return;
			var ix = shownCols(), q = function (v) { v = v === null ? '' : String(v); return /[",;\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
			var text = [ix.map(function (i) { return q(d.cols[i]); }).join(';')].concat(d.rows.map(function (r) { return ix.map(function (i) { return q(r[i]); }).join(';'); })).join('\r\n');
			var done = function () { U.toast(M.copied, true); };
			if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () { window.prompt(M.csv, text); });
			else window.prompt(M.csv, text);
		}
		N.browseRun = function (sql, cm) {
			if (!modal) build();
			st.sql = String(sql || ''); st.page = 1; st.cm = cm || null; st.data = null;
			modal.querySelector('#nuBrunSql').hidden = true;
			draw();
			if (bm) bm.show();
			run();
		};
		window.nuNextBrowseRun = N.browseRun;
	})();

