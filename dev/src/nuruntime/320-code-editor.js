	// =================================================================== code editor (nuBuilder nuOpenAce → CodeMirror dialog)
	// nuBuilder Forte: (excludeTypes, excludeIds) – object types first, then ids; content boxes / HTML never
	api('nuDisableAllObjects', function (excludeTypes, excludeIds) { nuEnableDisableAllObjects(false, arr(excludeTypes), arr(excludeIds)); });
	api('nuEnableAllObjects', function (excludeTypes, excludeIds) { nuEnableDisableAllObjects(true, arr(excludeTypes), arr(excludeIds)); });
	api('nuInsertTextAtCaret', function (id, text) {
		var el = g(id);
		if (!el) return;
		var a = el.selectionStart === undefined ? el.value.length : el.selectionStart, b = el.selectionEnd === undefined ? a : el.selectionEnd;
		el.value = el.value.slice(0, a) + text + el.value.slice(b);
		el.selectionStart = el.selectionEnd = a + String(text).length;
		markEdited(el);
		$(el).trigger('change');
	});
	var cmLoading = null;
	function loadCodeMirror() {
		if (window.CodeMirror && (window.NuCodeTools || !N.codetools)) return Promise.resolve();
		if (cmLoading) return cmLoading;
		if (window.CodeMirror) {                                         // CodeMirror of the page itself: only the toolbar is missing
			cmLoading = new Promise(function (ok) { var ct = document.createElement('script'); ct.src = N.codetools; ct.onload = ok; ct.onerror = ok; document.head.appendChild(ct); });
			return cmLoading;
		}
		cmLoading = new Promise(function (ok, fail) {
			var css = document.createElement('link');
			css.rel = 'stylesheet'; css.href = 'vendor/bundled/codemirror-6.43.15/codemirror.css';
			document.head.appendChild(css);
			var js = document.createElement('script');
			js.src = 'vendor/bundled/codemirror-6.43.15/codemirror-bundle.js';
			js.onload = function () {                     // then the editor toolbar (nucodetools.js, review B3)
				if (window.NuCodeTools || !N.codetools) { ok(); return; }
				var ct = document.createElement('script');
				ct.src = N.codetools; ct.onload = ok; ct.onerror = ok;
				document.head.appendChild(ct);
			};
			js.onerror = fail;
			document.head.appendChild(js);
		});
		return cmLoading;
	}
	// "Edit browse SQL…" in the ▾ of the list's Design button: the form's browse SQL in a pane on the right, saved with the
	// builder API (form.save – the open draft when one is previewed, a live change while a draft is open is confirmed) and reloaded
	(function () {
		var B = N.browse_sql, btn = document.getElementById('nuBrowseSqlEdit');
		if (!B || !btn) return;
		var T = B.m, pane = null, PIN = 'nunext-bsq-pin', TAB = 'nunext-bsq-tab', L = B.list || {}, ST = null, COLS = null, names = null;
		// code tabs: browse SQL, PHP Before Browse and the list's JavaScript – one editor each, made when its tab is first shown
		var TABS = ['sql', 'php', 'js', 'cols'], OPEN = {};
		var CODE = {sql: {cm: null, orig: B.sql, mode: 'text/x-sql', lang: 'SQL'}, php: {cm: null, orig: L.php || '', mode: 'application/x-httpd-php-open', lang: 'PHP'},
			js: {cm: null, orig: L.js || '', mode: 'javascript', lang: 'JS'}};
		function codeVal(k) { return CODE[k].cm ? CODE[k].cm.getValue() : CODE[k].orig; }
		function codeDirty() { return ['sql', 'php', 'js'].some(function (k) { return CODE[k].cm && CODE[k].cm.getValue() !== CODE[k].orig; }); }
		// tab Columns: the list's settings and columns as design mode's Browse list, edited here and saved with the pane's Save
		function stInit() {
			var d = L.details || {};
			ST = {rows: L.rows || '', rowh: L.rowh || '', target: L.target || '0', redirect: L.redirect || '', multi: !!L.multi, autofit: !!L.autofit,
				dcols: !!d.cols, down: !!d.own, dsubs: (d.subs || []).slice(), dform: d.form || '', dmode: d.mode === 'one' ? 'one' : 'many'};
			COLS = (L.cols
				|| []).map(function (c) { return {id: c[0], title: c[1], display: c[2], align: c[3] || 'l', format: c[4], width: c[5], filter: !!c[6], badge: c[7]
					|| null, action: c[8] || null, total: c[9] || null, group: !!c[10]}; });
		}
		var ST0 = '', COLS0 = '';
		function stDirty() { return !!ST && JSON.stringify(ST) !== ST0; }
		function colsDirty() { return !!COLS && JSON.stringify(COLS) !== COLS0; }
		// kept open: a pinned pane opens again after the reload of a save, on the next page and on other lists
		function pinned() { try { return localStorage.getItem(PIN) === '1'; } catch (e) { return false; } }
		function setPin(on) { try { if (on) localStorage.setItem(PIN, '1'); else localStorage.removeItem(PIN); } catch (e) { /* no storage */ } }
		function lastTab() { try { var k = localStorage.getItem(TAB); return TABS.indexOf(k) >= 0 ? k : 'sql'; } catch (e) { return 'sql'; } }
		function drawPin() {
			var b = pane && pane.querySelector('.nuBsqPin');
			if (!b) return;
			var on = pinned();
			b.classList.toggle('active', on);
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
			b.innerHTML = '<i class="bi ' + (on ? 'bi-pin-angle-fill' : 'bi-pin-angle') + '" aria-hidden="true"></i>';
		}
		function post(data, action) {
			return NuUtil.post(N.base + '?p=builderapi', $.extend({action: action || 'form.save', form_id: N.form.id}, N.draft ? {draft_id: N.draft.id, rev: N.draft.rev} : {}, data)).then(function (j) {
				if (!j.ok && j.code === 'draft_open_confirm' && !data.live_ok && window.confirm(j.error)) return post($.extend({}, data, {live_ok: true}), action);
				if (!j.ok) throw new Error(j.error || T.error);
				if (j.draft && N.draft) N.draft = j.draft;                      // the next call of the same save needs the new rev
				return j;
			});
		}
		function dirty() { return codeDirty() || stDirty() || colsDirty(); }
		function close(force) {
			if (!pane) return;
			if (!force && dirty() && !window.confirm(T.unsaved)) return;
			if (!force) setPin(false);                                       // closing it ends "keep open"
			pane.remove(); pane = null; ST = null; COLS = null; OPEN = {};
			['sql', 'php', 'js'].forEach(function (k) { CODE[k].cm = null; });
			document.documentElement.classList.remove('nuBsqOpen');
			btn.focus();
		}
		function save() {
			if (!pane || RUN) return;                                         // As run is read only
			var v = codeVal('sql'), vp = codeVal('php'), vj = codeVal('js'), sb = pane.querySelector('.nuBsqSave'), err = pane.querySelector('.nuBsqErr');
			var fields = {}, php = null;
			if (v !== CODE.sql.orig) fields.sfo_browse_sql = v;
			if (vj !== CODE.js.orig) fields.sfo_browse_javascript = vj;
			if (vp !== CODE.php.orig) php = {BB: vp};
			if (stDirty()) {
				var o = JSON.parse(ST0);
				if (ST.rows !== o.rows) fields.sfo_browse_rows_per_page = ST.rows;
				if (ST.rowh !== o.rowh) fields.sfo_browse_row_height = ST.rowh;
				if (ST.target !== o.target) fields.sfo_browse_target = ST.target;
				if (ST.redirect !== o.redirect) fields.sfo_browse_redirect_form_id = ST.redirect;
				if (ST.multi !== o.multi) fields.sfo_browse_title_multiline = ST.multi ? '1' : '0';
				if (ST.autofit !== o.autofit) fields.sfo_browse_autoresize_columns = ST.autofit ? '1' : '0';
				if (ST.dcols !== o.dcols || ST.down !== o.down || JSON.stringify(ST.dsubs) !== JSON.stringify(o.dsubs) || ST.dform !== o.dform || ST.dmode !== o.dmode)
					fields.details = ST.dcols || ST.down || ST.dsubs.length || ST.dform ? {cols: ST.dcols, own: ST.down, subs: ST.dsubs, form: ST.dform, mode: ST.dmode} : null;
			}
			var steps = [];
			if (colsDirty()) steps.push(function () { return post({columns: COLS}, 'browse.save'); });
			if (Object.keys(fields).length || php) steps.push(function () { return post(php ? {fields: fields, php: php} : {fields: fields}); });
			if (!steps.length) { close(true); return; }
			sb.disabled = true; err.hidden = true;
			steps.reduce(function (p, f) { return p.then(f); },
				Promise.resolve()).then(function () { B.sql = v; CODE.sql.orig = v; CODE.php.orig = vp; CODE.js.orig = vj; ST0 = JSON.stringify(ST); COLS0 = JSON.stringify(COLS); close(true); window.location.reload(); })
				.catch(function (e) { sb.disabled = false; err.textContent = e.message; err.hidden = false; });
		}
		function size() {
			if (!pane) return;
			['sql', 'php', 'js'].forEach(function (k) {
				var b = pane.querySelector('.nuBsqBody[data-code="' + k + '"]');
				if (CODE[k].cm && b && b.offsetParent) CODE[k].cm.setSize('100%', Math.max(120, b.clientHeight) + 'px');
			});
		}
		// a dot on the PHP / JavaScript tab while it holds code – as the Code list of design mode
		function codeMarks() {
			if (!pane) return;
			['php', 'js'].forEach(function (k) {
				var b = pane.querySelector('.nuBsqTab[data-tab="' + k + '"]'), has = String(codeVal(k)).trim() !== '';
				if (!b || b.classList.contains('nuHasCode') === has) return;
				b.classList.toggle('nuHasCode', has);
				if (has) { b.title = T.code_has; b.setAttribute('aria-label', b.textContent + ', ' + T.code_has); } else { b.removeAttribute('title'); b.removeAttribute('aria-label'); }
			});
		}
		// the editor of a code tab: made when the tab is first shown
		function codeOpen(k, focus) {
			var c = CODE[k];
			if (c.cm) { c.cm.refresh(); if (focus) c.cm.focus(); return; }
			loadCodeMirror().then(function () {
				if (!pane || c.cm) return;
				var ta = pane.querySelector('.nuBsqBody[data-code="' + k + '"] textarea');
				ta.value = c.orig;
				c.cm = CodeMirror.fromTextArea(ta, {mode: c.mode, lineNumbers: true, lineWrapping: true, indentWithTabs: true, tabSize: 4,
					theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'default nuCmDark' : 'default'});
				if (k !== 'sql') c.cm.on('change', codeMarks);
				if (window.NuCodeTools) NuCodeTools.attach(c.cm, pane.querySelector('.nuBsqTools[data-code="' + k + '"]'), $.extend({lang: c.lang, db: N.db_type},
					k === 'sql' && N.browseRun ? {run: function (ed) { N.browseRun(ed.getValue(), ed); }, runLabel: N.browse_run.m.run, runTitle: N.browse_run.m.run_tip} : {}));   // ▶ Run the browse SQL
				size();
				if (focus) c.cm.focus();
			});
		}
		// what a column offers beyond the four fields of its row: format, filter, badge and what a click on its cells does
		function fmtOptions(value) {
			var kind = {D: T.fmt_type_date, N: T.fmt_type_number, T: T.fmt_type_text};
			var nice = function (x) { var n = kind[String(x).charAt(0)]; return n && String(x).charAt(1) === '|' ? n + ': ' + String(x).substring(2) : String(x); };
			var out = [['', '—']].concat((L.formats || []).map(function (f) { return [f.value, nice(f.value) + (f['default'] ? ' ★' : '') + (f.description ? ' – ' + f.description : '')]; }));
			if (value && !(L.formats || []).some(function (f) { return f.value === value; })) out.push([value, value]);
			return out;
		}
		function colMore(c, i) {
			var tg = L.targets || {}, a = c.action || {t: ''}, uid = 'nuBsqC' + i + '_';
			var fld = function (key, txt, input, tip) {
				input.id = uid + key; input.setAttribute('data-lm', key);
				return mk('div', {'class': 'nuBsqField'}, [mk('label', {'class': 'form-label small mb-1', 'for': uid + key, text: txt}),
					tip ? mk('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', title: tip, 'aria-label': tip}, [ico('bi-info-circle')]) : null, input]);
			};
			var sel = function (opts, val, fn, search) {
				var x = mk('select', {'class': 'form-select form-select-sm'}, opts.map(function (o) { return mk('option', {value: o[0], text: o[1], selected: String(val) === String(o[0])}); }));
				x.addEventListener('change', function () { fn(x.value); });
				if (search) x.setAttribute('data-ts', '1');
				return x;
			};
			var txt = function (val, fn,
				ph) { var x = mk('input',
					{type: 'text', 'class': 'form-control form-control-sm nuBsqMono', value: val, placeholder: ph || null}); x.addEventListener('input',
					function () { fn(x.value); }); return x; };
			var chk = function (key, label, on, fn, tip) {
				var x = mk('input', {type: 'checkbox', 'class': 'form-check-input', id: uid + key, 'data-lm': key, checked: on});
				x.addEventListener('change', function () { fn(x.checked); });
				return mk('div', {'class': 'form-check form-switch'}, [x, mk('label', {'class': 'form-check-label small', 'for': uid + key, text: label}),
					tip ? mk('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', title: tip, 'aria-label': tip}, [ico('bi-info-circle')]) : null]);
			};
			var act = function (part, redraw) {
				var n = $.extend({}, a, part);
				if (part.t !== undefined && part.t !== a.t) n = {t: part.t, look: a.look || 'link'};       // another kind: its own target
				c.action = n.t ? n : null; a = n;
				if (redraw) drawCols();
			};
			var pick = function (list,
				types) { return [['', T.please_select]].concat((list || []).filter(function (f) { return !types || types.indexOf(f[2]) >= 0; }).map(function (f) { return [f[0], f[1]]; })); };
			var rules = Array.isArray(c.badge) ? c.badge.length : 0;
			var kids = [mk('div', {'class': 'nuBsqGrid'}, [
				fld('format', T.builder_col_format, sel(fmtOptions(c.format), c.format, function (v) { c.format = v; })),
				mk('div', {'class': 'nuBsqMoreChecks'}, [chk('filter', T.builder_col_filter, c.filter, function (on) { c.filter = on; }),
					chk('badge', T.ds_col_badge, !!c.badge, function (on) { c.badge = on ? (c.badge || true) : null; }, T.ds_col_badge_tip),
					rules ? mk('div', {'class': 'small text-body-secondary nuBsqBadgeRules', text: String(T.bsq_badge_rules || '').replace('%d', String(rules))}) : null]),
				fld('total', T.ds_col_total,
					sel([['', T.ds_tot_none], ['sum', T.ds_tot_sum], ['avg', T.ds_tot_avg], ['min', T.ds_tot_min], ['max', T.ds_tot_max], ['count', T.ds_tot_count]], c.total || '', function (v) { c.total = v || null; }), T.ds_col_total_tip),
				mk('div', {'class': 'nuBsqMoreChecks'},
					[chk('group', T.ds_col_group, !!c.group, function (on) { COLS.forEach(function (x) { x.group = false; }); c.group = on; drawCols(); }, T.ds_col_group_tip)])])];
			var g = [fld('action_t', T.ds_col_action, sel([['', T.ds_act_none], ['form', T.ds_act_form], ['list', T.ds_act_list], ['proc', T.ds_act_proc], ['report', T.ds_act_report], ['js', T.ds_act_js]],
				a.t || '', function (v) { act({t: v}, true); }), T.ds_col_action_tip)];
			if (a.t === 'form') g.push(fld('action_id', T.ds_act_target_form, sel(pick(tg.forms, ['edit', 'browseedit']), a.id || '', function (v) { act({id: v}, true); }, true)));
			if (a.t === 'list') g.push(fld('action_id', T.ds_act_target_list, sel(pick(tg.forms, ['browse', 'browseedit']), a.id || '', function (v) { act({id: v}, true); }, true)));
			if (a.t === 'proc') g.push(fld('action_id', T.builder_run_procedure, sel(pick(tg.procs), a.id || '', function (v) { act({id: v}, true); }, true)));
			if (a.t === 'report') g.push(fld('action_id', T.builder_run_report, sel(pick(tg.reports), a.id || '', function (v) { act({id: v}, true); }, true)));
			if (a.t === 'js') g.push(fld('action_id', T.ds_act_fn, txt(a.id || '', function (v) { act({id: v.trim()}); }, 'openReadings'), T.ds_act_fn_tip));
			if (a.t === 'form') g.push(fld('action_rec', T.ds_act_rec,
				sel([['row', T.ds_act_rec_row], ['value', T.ds_act_rec_value], ['new', T.ds_act_rec_new]], a.rec || 'row', function (v) { act({rec: v}); })));
			if (a.t === 'form'
				|| a.t === 'list') g.push(fld('action_open', T.ds_act_open,
					sel([['0', T.ds_act_open_same], ['2', T.ds_act_open_tab], ['3', T.ds_act_open_popup]], a.open || '0', function (v) { act({open: v}); })));
			if (a.t && a.t !== 'js') g.push(fld('action_pass', T.ds_act_pass, txt(a.pass || 'PARENT_ID', function (v) { act({pass: v.trim()}); }), T.ds_act_pass_tip));
			if (a.t) g.push(fld('action_look', T.ds_act_look,
				sel([['link', T.ds_act_look_link], ['button', T.ds_act_look_button], ['icon', T.ds_act_look_icon]], a.look || 'link', function (v) { act({look: v}, true); })));
			if (a.t && (a.look === 'button' || a.look === 'icon')) {
				g.push(fld('action_icon', T.ds_act_icon, txt(a.icon || '', function (v) { act({icon: v.trim()}); }, 'bi-speedometer2'), T.ds_act_icon_tip));
				g.push(fld('action_label', T.ds_act_label, txt(a.label || '', function (v) { act({label: v}); }), T.ds_act_label_tip));
			}
			kids.push(mk('div', {'class': 'nuBsqGrid mt-2'}, g));
			if (a.t && !a.id) kids.push(mk('p', {'class': 'small text-warning-emphasis mb-0 mt-1 nuBsqActNoTarget', text: T.ds_act_need_target}));
			var box = mk('div', {'class': 'nuBsqMore'}, kids);
			// lists of forms, procedures and reports are searchable: Tom Select, its list in <body> above the pane
			if (window.TomSelect) Array.prototype.forEach.call(box.querySelectorAll('select[data-ts]'), function (x) {
				new TomSelect(x, {maxOptions: null, allowEmptyOption: true, create: false, dropdownParent: 'body', plugins: ['dropdown_input'], onItemAdd: function () { this.close(); this.blur(); }});
			});
			return box;
		}
		function mk(tag, attrs, kids) {
			var e = document.createElement(tag);
			Object.keys(attrs
				|| {}).forEach(function (k) { if (attrs[k] === null || attrs[k] === undefined
					|| attrs[k] === false) return; if (k === 'text') e.textContent = attrs[k]; else e.setAttribute(k, attrs[k] === true ? '' : attrs[k]); });
			(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
			return e;
		}
		function ico(c) { return mk('i', {'class': 'bi ' + c, 'aria-hidden': 'true'}); }
		function namesList() {
			var dl = mk('datalist', {id: 'nuBsqColNames'});
			var fill = function () { dl.innerHTML = ''; (names || []).forEach(function (n) { dl.appendChild(mk('option', {value: n})); }); };
			if (names === null && L.table) {
				names = [];
				post({table: L.table}, 'columns').then(function (j) { names = (j.columns || []).map(function (c) { return c.name; }); fill(); }).catch(function () { /* list without suggestions */ });
			}
			fill();
			return dl;
		}
		function drawCols() {
			var box = pane && pane.querySelector('.nuBsqCols');
			if (!box) return;
			box.innerHTML = '';
			var lab = function (txt, input, tip) {
				var id = 'nuBsq_' + input.getAttribute('data-bsq');
				input.id = id;
				return mk('div', {'class': 'nuBsqField'},
					[mk('label', {'class': 'form-label small mb-1', 'for': id, text: txt}),
						tip ? mk('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', title: tip, 'aria-label': tip}, [ico('bi-info-circle')]) : null, input]);
			};
			var num = function (k) { var i = mk('input',
				{type: 'number', min: '0', 'class': 'form-control form-control-sm', 'data-bsq': k, value: ST[k]}); i.addEventListener('input',
				function () { ST[k] = i.value.trim(); }); return i; };
			var sel = function (k, opts) {
				var s = mk('select', {'class': 'form-select form-select-sm', 'data-bsq': k}, opts.map(function (o) { return mk('option', {value: o[0], text: o[1], selected: String(ST[k]) === String(o[0])}); }));
				s.addEventListener('change', function () { ST[k] = s.value; });
				return s;
			};
			var chk = function (k, txt) {
				var i = mk('input', {type: 'checkbox', 'class': 'form-check-input', id: 'nuBsq_' + k, 'data-bsq': k, checked: ST[k]});
				i.addEventListener('change', function () { ST[k] = i.checked; });
				return mk('div', {'class': 'form-check form-switch'}, [i, mk('label', {'class': 'form-check-label small', 'for': 'nuBsq_' + k, text: txt})]);
			};
			// Settings fold away (remembered in this browser): the heading is the switch
			var setOpen = true;
			try { setOpen = localStorage.getItem('nunext-bsq-settings') !== '0'; } catch (e) { setOpen = true; }
			var setBody = mk('div', {id: 'nuBsqSetBody'});
			var setBtn = mk('button', {type: 'button', 'class': 'nuBsqFold', id: 'nuBsqSetToggle', 'aria-controls': 'nuBsqSetBody'}, [ico('bi-chevron-down'), T.ds_list_settings]);
			var setShow = function (on) { setBody.hidden = !on; setBtn.setAttribute('aria-expanded', on ? 'true' : 'false'); };
			setBtn.addEventListener('click', function () {
				setOpen = !setOpen; setShow(setOpen);
				nuStore.set('nunext-bsq-settings', setOpen ? '1' : '0');
			});
			setShow(setOpen);
			box.appendChild(mk('h3', {'class': 'nuBsqSub'}, [setBtn]));
			box.appendChild(setBody);
			setBody.appendChild(mk('div', {'class': 'nuBsqGrid'}, [lab(T.builder_rows_per_page, num('rows')), lab(T.ds_list_rowh, num('rowh'), T.ds_list_rowh_tip),
				lab(T.ds_list_target, sel('target', ['0', '1', '2', '3'].map(function (x) { return [x, T['ds_list_target_' + x]]; }))),
				lab(T.builder_redirect, sel('redirect', [['', T.please_select]].concat(L.forms || [])))]));
			// lists of forms are searchable: Tom Select, its list in <body> above the pane
			var rs = box.querySelector('[data-bsq=redirect]');
			if (rs && window.TomSelect) new TomSelect(rs, {maxOptions: null, allowEmptyOption: true, create: false, dropdownParent: 'body', plugins: ['dropdown_input'],
				onItemAdd: function () { this.close(); this.blur(); }});
			setBody.appendChild(mk('div', {'class': 'nuBsqChecks'}, [chk('multi', T.ds_list_multi), chk('autofit', T.ds_list_autofit)]));
			box.appendChild(mk('h3', {'class': 'nuBsqSub', text: T.builder_sec_columns}));
			var rowDetails = function () {                                       // below the columns: what the ▸ of a row shows
				box.appendChild(mk('h3', {'class': 'nuBsqSub', text: T.ds_rd_title}));
				var dc = chk('dcols', T.ds_rd_cols);
				dc.appendChild(mk('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', title: T.ds_rd_cols_tip, 'aria-label': T.ds_rd_cols_tip}, [ico('bi-info-circle')]));
				var lists = (L.detail_lists || []).slice();
				ST.dsubs.forEach(function (k) { if (!lists.some(function (c) { return c[0] === k; })) lists.push([k, k]); });
				var lw = mk('div', {'class': 'nuBsqChecks nuBsqDetLists'});
				lists.forEach(function (c, i) {
					var cb = mk('input', {type: 'checkbox', 'class': 'form-check-input', id: 'nuBsq_dl' + i, 'data-bsq-list': c[0], checked: ST.dsubs.indexOf(c[0]) >= 0});
					cb.addEventListener('change', function () {
						var at = ST.dsubs.indexOf(c[0]);
						if (cb.checked && at < 0) ST.dsubs.push(c[0]); else if (!cb.checked && at >= 0) ST.dsubs.splice(at, 1);
					});
					lw.appendChild(mk('div', {'class': 'form-check'}, [cb, mk('label', {'class': 'form-check-label small', 'for': 'nuBsq_dl' + i, text: c[1]})]));
				});
				if (!lists.length) lw.appendChild(mk('div', {'class': 'small text-body-secondary', text: T.ds_rd_no_lists}));
				var dw = chk('down', T.ds_rd_own);
				dw.appendChild(mk('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', title: T.ds_rd_own_tip, 'aria-label': T.ds_rd_own_tip}, [ico('bi-info-circle')]));
				box.appendChild(mk('div', {'class': 'nuBsqGrid'}, [mk('div', {}, [dc, dw]), lab(T.ds_rd_mode, sel('dmode', [['many', T.ds_rd_mode_many], ['one', T.ds_rd_mode_one]]))]));
				box.appendChild(mk('div', {'class': 'nuBsqField mt-2'}, [mk('div', {'class': 'form-label small mb-1', text: T.ds_rd_lists}), lw]));
				box.appendChild(mk('div', {'class': 'nuBsqGrid mt-2'}, [lab(T.ds_rd_form, sel('dform', [['', T.ds_rd_none]].concat((L.forms || []))), T.ds_rd_form_tip)]));
				var dfs = box.querySelector('[data-bsq=dform]');
				if (dfs && window.TomSelect) new TomSelect(dfs, {maxOptions: null, allowEmptyOption: true, create: false, dropdownParent: 'body', plugins: ['dropdown_input'],
					onItemAdd: function () { this.close(); this.blur(); }});
				if (L.access) box.appendChild(mk('p', {'class': 'small mt-3 mb-0'},
					[mk('a', {href: L.access, target: '_blank', rel: 'noopener', id: 'nuBsqAccess'}, [ico('bi-shield-lock me-1'), T.bsq_col_access])]));
			};
			var tbody = mk('tbody');
			var head = mk('tr', {}, ['#', T.builder_col_title, T.builder_col_display, T.builder_col_width, T.builder_col_align, ''].map(function (x) { return mk('th', {scope: 'col', text: x}); }));
			var marks = function (c) { return !!(c.format || c.filter || c.badge || c.action || c.total || c.group); };
			box.appendChild(mk('table', {'class': 'table table-sm align-middle nuBsqColTable', id: 'nuBsqColTable'}, [mk('thead', {}, [head]), tbody]));
			COLS.forEach(function (c, i) {
				var off = +c.width === 0;
				var title = mk('input', {type: 'text', 'class': 'form-control form-control-sm', value: c.title, 'aria-label': T.builder_col_title, 'data-lc': 'title'});
				var disp = mk('input', {type: 'text', 'class': 'form-control form-control-sm nuBsqMono', value: c.display, list: 'nuBsqColNames', 'aria-label': T.builder_col_display, 'data-lc': 'display'});
				var width = mk('input',
					{type: 'number', min: '1', 'class': 'form-control form-control-sm', value: off ? '' : String(c.width), disabled: off, 'aria-label': T.builder_col_width, 'data-lc': 'width'});
				var align = mk('select', {'class': 'form-select form-select-sm', 'aria-label': T.builder_col_align, 'data-lc': 'align'},
					['l', 'c', 'r'].map(function (a) { return mk('option', {value: a, text: T[{l: 'om_left', c: 'om_center', r: 'om_right'}[a]], selected: c.align === a}); }));
				title.addEventListener('input', function () { c.title = title.value; });
				disp.addEventListener('input', function () { c.display = disp.value.trim(); });
				width.addEventListener('input', function () { c.width = Math.max(1, parseInt(width.value, 10) || 100); });
				align.addEventListener('change', function () { c.align = align.value; });
				var b = function (ic, tip, cls, fn,
					dis) { var x = mk('button',
						{type: 'button', 'class': 'btn btn-sm btn-link p-0 ' + (cls || 'text-body-secondary'), title: tip, 'aria-label': tip, disabled: dis, 'data-act': ic},
						[ico(ic)]); x.addEventListener('click', fn); return x; };
				var move = function (d) { var x = COLS.splice(i, 1)[0]; COLS.splice(i + d, 0, x); OPEN = {}; drawCols(); };
				tbody.appendChild(mk('tr', {'class': 'nuBsqCol' + (off ? ' nuBsqColOff' : ''), 'data-col': c.id || ('new' + i)}, [
					mk('td', {'class': 'nuBsqColNo'}, [mk('span', {'class': 'nuBsqGrip', draggable: 'true', 'data-grip': String(i), title: T.ds_col_drag}, [ico('bi-grip-vertical'), String(i + 1)])]),
						mk('td', {}, [title]), mk('td', {}, [disp]), mk('td', {'class': 'nuBsqColW'}, [width]), mk('td', {'class': 'nuBsqColA'}, [align]),
					mk('td', {'class': 'nuBsqColBtns'}, [
						b(OPEN[i] ? 'bi-chevron-up' : 'bi-sliders', T.bsq_col_more, marks(c) ? 'text-primary nuBsqMoreBtn' : 'text-body-secondary nuBsqMoreBtn', function () { OPEN[i] = !OPEN[i]; drawCols(); }),
						b(off ? 'bi-eye-slash' : 'bi-eye', off ? T.ds_col_show : T.ds_col_hidden, '', function () { c.width = off ? 100 : 0; drawCols(); }),
						b('bi-arrow-up', T.ds_col_left, '', function () { move(-1); }, i === 0),
						b('bi-arrow-down', T.ds_col_right, '', function () { move(1); }, i === COLS.length - 1),
						b('bi-trash', T.delete, 'text-danger', function () { COLS.splice(i, 1); OPEN = {}; drawCols(); })])]));
				if (OPEN[i]) tbody.appendChild(mk('tr', {'class': 'nuBsqMoreRow', 'data-more': c.id || ('new' + i)}, [mk('td'), mk('td', {colspan: '5'}, [colMore(c, i)])]));
			});
			if (!COLS.length) tbody.appendChild(mk('tr', {}, [mk('td', {colspan: '6', 'class': 'small text-body-secondary', text: T.ds_list_nocols})]));
			// drag by the number: drop above / below the row under the pointer
			var from = -1, mark = function (tr, after) {
				tbody.querySelectorAll('.nuBsqDropBefore, .nuBsqDropAfter').forEach(function (x) { x.classList.remove('nuBsqDropBefore', 'nuBsqDropAfter'); });
				if (tr) tr.classList.add(after ? 'nuBsqDropAfter' : 'nuBsqDropBefore');
			};
			tbody.addEventListener('dragstart', function (e) {
				var g = e.target.closest && e.target.closest('[data-grip]');
				if (!g) { e.preventDefault(); return; }
				from = +g.getAttribute('data-grip');
				e.dataTransfer.effectAllowed = 'move';
				try { e.dataTransfer.setData('text/plain', String(from)); } catch (x) { /* ignore */ }
				var tr = g.closest('tr'); if (tr) { tr.classList.add('nuBsqDragging'); try { e.dataTransfer.setDragImage(tr, 20, 15); } catch (x) { /* ignore */ } }
			});
			tbody.addEventListener('dragover', function (e) {
				if (from < 0) return;
				var tr = e.target.closest && e.target.closest('tr.nuBsqCol');
				if (!tr) return;
				e.preventDefault();
				var r = tr.getBoundingClientRect();
				mark(tr, e.clientY > r.top + r.height / 2);
			});
			tbody.addEventListener('dragleave', function (e) { if (!tbody.contains(e.relatedTarget)) mark(null); });
			tbody.addEventListener('dragend', function () { from = -1; mark(null); tbody.querySelectorAll('.nuBsqDragging').forEach(function (x) { x.classList.remove('nuBsqDragging'); }); });
			tbody.addEventListener('drop', function (e) {
				var tr = e.target.closest && e.target.closest('tr.nuBsqCol');
				if (from < 0 || !tr) return;
				e.preventDefault();
				var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.nuBsqCol')), to = rows.indexOf(tr), r = tr.getBoundingClientRect();
				if (e.clientY > r.top + r.height / 2) to++;
				var f = from; from = -1; mark(null);
				if (to > f) to--;
				if (to === f || to < 0) { drawCols(); return; }
				var x = COLS.splice(f, 1)[0]; COLS.splice(to, 0, x); OPEN = {}; drawCols();
			});
			box.appendChild(namesList());
			var add = mk('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary', id: 'nuBsqAddCol'}, [ico('bi-plus-lg me-1'), T.builder_add_column]);
			add.addEventListener('click', function () {
				COLS.push({id: '', title: T.ds_col_new, display: '', align: 'l', format: '', width: 120, filter: false, badge: null, action: null});
				drawCols();
				var inp = box.querySelectorAll('input[data-lc="display"]');
				if (inp.length) inp[inp.length - 1].focus();
			});
			box.appendChild(add);
			rowDetails();
		}
		// Browse SQL tab, Written | As run: the SQL this page ran with its hash variables filled in (B.run.parts from
		// NuBrowse::$shown), read only – filled values green, unset ones amber, the variables listed below; Save is off meanwhile
		var RUNKEY = 'nunext-bsq-run', RUN = false;
		try { RUN = !!B.run && localStorage.getItem(RUNKEY) === '1'; } catch (e) { RUN = false; }
		function runText() {
			return (B.run.parts || []).map(function (x) { return typeof x === 'string' ? x : (x[1] === null ? '#' + x[0] + '#' : x[1]); }).join('');
		}
		function drawRun() {
			var box = pane && pane.querySelector('.nuBsqRun');
			if (!box || !B.run) return;
			box.innerHTML = '<div class="nuBsqRunNote"><i class="bi bi-lock" aria-hidden="true"></i><span></span>'
				+ '<button type="button" class="btn btn-sm btn-outline-secondary ms-auto nuBsqRunCopy"><i class="bi bi-clipboard me-1" aria-hidden="true"></i></button></div>'
				+ '<ol class="nuBsqRunSql" tabindex="0"></ol><div class="nuBsqRunVars"></div>';
			box.querySelector('.nuBsqRunNote span').textContent = T.bsq_run_note;
			box.querySelector('.nuBsqRunCopy').appendChild(document.createTextNode(T.copy));
			box.querySelector('.nuBsqRunCopy').setAttribute('data-nu-copy-text', runText());
			var ol = box.querySelector('.nuBsqRunSql'), li = document.createElement('li');
			ol.appendChild(li);
			function text(t, into) {
				String(t).split('\n').forEach(function (bit, i) {
					if (i) { li = document.createElement('li'); ol.appendChild(li); into = li; }
					if (bit !== '') into.appendChild(document.createTextNode(bit));
				});
			}
			(B.run.parts || []).forEach(function (x) {
				if (typeof x === 'string') { text(x, li); return; }
				var m = document.createElement('mark');
				m.className = x[1] === null ? 'nuBsqUnset' : 'nuBsqFill';
				m.title = '#' + x[0] + '# – ' + (x[1] === null ? T.fi_hash_unset : T.bsq_run_filled);
				text(x[1] === null ? '#' + x[0] + '#' : x[1], m);
				li.appendChild(m);
			});
			var vars = B.run.vars || {}, names = Object.keys(vars), vb = box.querySelector('.nuBsqRunVars');
			var h = document.createElement('div');
			h.className = 'nuBsqRunHead';
			h.textContent = names.length ? T.bsq_run_vars.replace('%d', names.length) : T.bsq_run_none;
			vb.appendChild(h);
			names.forEach(function (n) {
				var r = document.createElement('div'), unset = vars[n] === null;
				r.className = 'nuBsqRunVar' + (unset ? ' nuBsqRunNo' : '');
				r.setAttribute('data-var', n);
				r.innerHTML = '<code></code><span class="nuBsqRunVal"></span><span class="badge"></span>';
				r.querySelector('code').textContent = '#' + n + '#';
				r.querySelector('.nuBsqRunVal').textContent = unset ? T.fi_hash_unset : vars[n];
				r.querySelector('.badge').textContent = unset ? T.bsq_run_unset : T.bsq_run_filled;
				r.querySelector('.badge').className = 'badge ' + (unset ? 'nuBsqBadgeUnset' : 'nuBsqBadgeFill');
				vb.appendChild(r);
			});
		}
		function setRun(on) {
			RUN = !!on && !!B.run;
			nuStore.set(RUNKEY, RUN ? '1' : '0');
			if (!pane) return;
			pane.querySelectorAll('.nuBsqMode button').forEach(function (b) { var a = (b.getAttribute('data-mode') === 'run') === RUN; b.classList.toggle('active',
				a); b.setAttribute('aria-pressed', a ? 'true' : 'false'); });
			pane.classList.toggle('nuBsqRunOn', RUN);
			pane.querySelector('.nuBsqSave').disabled = RUN;
			var cur = pane.querySelector('.nuBsqTab.active');
			if (cur && cur.getAttribute('data-tab') === 'sql') showTab('sql', !RUN);
		}
		function modeSwitch() {
			var t = pane && pane.querySelector('.nuBsqTools[data-code="sql"]');
			if (!t || !B.run || t.querySelector('.nuBsqMode')) return;
			var g = document.createElement('div');
			g.className = 'btn-group btn-group-sm nuSeg nuBsqMode';
			g.setAttribute('role', 'group');
			g.setAttribute('aria-label', T.bsq_run_mode);
			[['written', T.bsq_written], ['run', T.bsq_as_run]].forEach(function (x) {
				var b = document.createElement('button');
				b.type = 'button'; b.className = 'btn btn-outline-secondary'; b.setAttribute('data-mode', x[0]); b.textContent = x[1];
				b.addEventListener('click', function () { setRun(x[0] === 'run'); });
				g.appendChild(b);
			});
			t.appendChild(g);
		}
		function showTab(k, focus) {
			if (!pane) return;
			pane.querySelectorAll('.nuBsqTab').forEach(function (b) { var on = b.getAttribute('data-tab') === k; b.classList.toggle('active',
				on); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
			pane.querySelectorAll('.nuBsqBody, .nuBsqTools').forEach(function (x) { x.hidden = x.getAttribute('data-code') !== k; });
			if (k === 'sql' && RUN) pane.querySelector('.nuBsqBody[data-code="sql"]').hidden = true;
			pane.querySelector('.nuBsqRun').hidden = !(k === 'sql' && RUN);
			pane.querySelector('.nuBsqCols').hidden = k !== 'cols';
			var hint = pane.querySelector('.nuBsqHint');
			hint.hidden = k === 'cols';
			nuStore.set(TAB, k);
			if (CODE[k]) { codeOpen(k, focus === true && !(k === 'sql' && RUN)); size(); }
		}
		// width by the splitter on the left edge: stored per browser, 360 px … 90 % of the window, double-click = default
		var WKEY = 'nunext-bsq-w', WDEF = 560;
		function clampW(w) { return Math.round(Math.max(360, Math.min(w, Math.max(360, window.innerWidth * 0.9)))); }
		function setW(w, save) {
			w = clampW(w);
			document.documentElement.style.setProperty('--nu-bsq-w', w + 'px');
			var sp = pane && pane.querySelector('.nuBsqSplit'); if (sp) sp.setAttribute('aria-valuenow', String(w));
			if (save) { try { if (w === WDEF) localStorage.removeItem(WKEY); else localStorage.setItem(WKEY, String(w)); } catch (e) { /* no storage */ } }
			size();
		}
		function splitter() {
			var saved = 0;
			try { saved = parseInt(localStorage.getItem(WKEY) || '0', 10) || 0; } catch (e) { /* no storage */ }
			var sp = document.createElement('div');
			sp.className = 'nuBsqSplit';
			sp.setAttribute('role', 'separator'); sp.setAttribute('aria-orientation', 'vertical'); sp.tabIndex = 0;
			sp.setAttribute('aria-valuemin', '360'); sp.setAttribute('aria-label', T.resize || '');
			if (T.resize) sp.title = T.resize;
			pane.appendChild(sp);
			setW(saved || WDEF, false);
			sp.addEventListener('pointerdown', function (e) {
				if (e.button !== 0) return;
				e.preventDefault();
				try { sp.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
				document.documentElement.classList.add('nuBsqResizing');
				var vw = function () { return document.documentElement.clientWidth; };   // without the scroll bar, as the fixed pane
				var off = vw() - e.clientX - pane.getBoundingClientRect().width;   // where on the splitter it was grabbed
				var move = function (ev) { setW(vw() - ev.clientX - off, false); };
				var up = function (ev) {
					sp.removeEventListener('pointermove', move); sp.removeEventListener('pointerup', up); sp.removeEventListener('pointercancel', up);
					document.documentElement.classList.remove('nuBsqResizing');
					setW(vw() - ev.clientX - off, true);
				};
				sp.addEventListener('pointermove', move); sp.addEventListener('pointerup', up); sp.addEventListener('pointercancel', up);
			});
			sp.addEventListener('dblclick', function () { setW(WDEF, true); });
			sp.addEventListener('keydown', function (e) {
				var w = pane.getBoundingClientRect().width;
				if (e.key === 'ArrowLeft') { e.preventDefault(); setW(w + 20, true); }
				else if (e.key === 'ArrowRight') { e.preventDefault(); setW(w - 20, true); }
				else if (e.key === 'Home') { e.preventDefault(); setW(WDEF, true); }
			});
		}
		function open(auto, tab) {
			if (tab) { nuStore.set(TAB, tab); }
			if (pane) { if (tab) showTab(tab, true); if (!tab
				|| tab === 'cols') { var cur = lastTab(); if (CODE[cur] && CODE[cur].cm) CODE[cur].cm.focus(); else pane.querySelector('.nuBsqTab.active').focus(); } return; }
			stInit(); ST0 = JSON.stringify(ST); COLS0 = JSON.stringify(COLS);
			pane = document.createElement('aside');
			pane.className = 'nuBsqPane';
			pane.id = 'nuBsqPane';
			pane.setAttribute('aria-label', T.title);
			pane.innerHTML = '<div class="nuBsqHead"><ul class="nav nav-tabs nuBsqTabs me-auto" role="tablist"><li class="nav-item" role="presentation"><button type="button" class="nav-link nuBsqTab" role="tab" data-tab="sql" id="nuBsqTabSql"></button></li>'
				+ '<li class="nav-item" role="presentation"><button type="button" class="nav-link nuBsqTab" role="tab" data-tab="php" id="nuBsqTabPhp"></button></li>'
				+ '<li class="nav-item" role="presentation"><button type="button" class="nav-link nuBsqTab" role="tab" data-tab="js" id="nuBsqTabJs"></button></li>'
				+ '<li class="nav-item" role="presentation"><button type="button" class="nav-link nuBsqTab" role="tab" data-tab="cols" id="nuBsqTabCols"></button></li></ul>'
				+ '<button type="button" class="btn btn-sm btn-outline-secondary nuBsqPin" id="nuBsqPin"></button><button type="button" class="btn-close nuBsqClose"></button></div>'
				+ '<div class="alert alert-danger py-1 px-2 m-2 mb-0 small nuBsqErr" role="alert" hidden></div><div class="nuBsqTools" data-code="sql"></div><div class="nuBsqBody" data-code="sql" role="tabpanel" aria-labelledby="nuBsqTabSql"><textarea></textarea></div><div class="nuBsqRun" hidden></div>'
				+ '<div class="nuBsqTools" data-code="php" hidden></div><div class="nuBsqBody" data-code="php" role="tabpanel" aria-labelledby="nuBsqTabPhp" hidden><textarea></textarea></div>'
				+ '<div class="nuBsqTools" data-code="js" hidden></div><div class="nuBsqBody" data-code="js" role="tabpanel" aria-labelledby="nuBsqTabJs" hidden><textarea></textarea></div>'
				+ '<div class="nuBsqCols" role="tabpanel" aria-labelledby="nuBsqTabCols" hidden></div>'
				+ '<div class="nuBsqFoot"><span class="small text-body-secondary nuBsqHint"></span><button type="button" class="btn btn-outline-secondary btn-sm nuBsqCancel"></button><button type="button" class="btn btn-primary btn-sm nuBsqSave"></button></div>';
			pane.querySelector('#nuBsqTabSql').textContent = T.title;
			pane.querySelector('#nuBsqTabPhp').textContent = T.bsq_tab_php;
			pane.querySelector('#nuBsqTabJs').textContent = T.bsq_tab_js;
			pane.querySelector('#nuBsqTabCols').textContent = T.builder_sec_columns;
			codeMarks();
			pane.querySelectorAll('.nuBsqTab').forEach(function (b) { b.addEventListener('click', function () { showTab(b.getAttribute('data-tab')); }); });
			pane.querySelector('.nuBsqTabs').addEventListener('keydown', function (e) {
				if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
				var at = TABS.indexOf(pane.querySelector('.nuBsqTab.active').getAttribute('data-tab'));
				var k = TABS[(at + (e.key === 'ArrowRight' ? 1 : TABS.length - 1)) % TABS.length];
				showTab(k); pane.querySelector('.nuBsqTab[data-tab="' + k + '"]').focus(); e.preventDefault();
			});
			pane.querySelector('.nuBsqHint').textContent = T.hint;
			pane.querySelector('.nuBsqCancel').textContent = T.close;
			pane.querySelector('.nuBsqClose').setAttribute('aria-label', T.close);
			pane.querySelector('.nuBsqPin').setAttribute('title', T.pin);
			pane.querySelector('.nuBsqPin').setAttribute('aria-label', T.pin);
			pane.querySelector('.nuBsqPin').addEventListener('click', function () { setPin(!pinned()); drawPin(); });
			drawPin();
			var stb = saveTargetBadge();                                   // where Save goes
			if (stb) { stb.insertBefore(pane.querySelector('.nuBsqPin')); saveTargetTip(stb[0]); }
			pane.querySelector('.nuBsqSave').innerHTML = '<i class="bi bi-floppy me-1" aria-hidden="true"></i>';
			pane.querySelector('.nuBsqSave').appendChild(document.createTextNode(T.save));
			document.body.appendChild(pane);
			document.documentElement.classList.add('nuBsqOpen');
			splitter();
			drawCols();
			modeSwitch();
			drawRun();
			setRun(RUN);
			var first = lastTab();
			showTab(first, auto !== true);                                   // a pane opened by itself leaves the focus on the list
			pane.querySelector('.nuBsqClose').addEventListener('click', function () { close(); });
			pane.querySelector('.nuBsqCancel').addEventListener('click', function () { close(); });
			pane.querySelector('.nuBsqSave').addEventListener('click', save);
			pane.addEventListener('keydown', function (e) {
				if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); e.stopPropagation(); save(); }
			});
			// Esc closes the pane also from inside a code editor (the editor took the key) – in the capture phase, before the
			// editor; left to the editor while its search panel or suggestion list is open, and to an open menu / select list of the pane
			pane.addEventListener('keydown', function (e) {
				if (e.key !== 'Escape' || document.querySelector('.modal.show')) return;
				if (pane.querySelector('.cm-panel.cm-search, .cm-tooltip-autocomplete, .dropdown-menu.show') || document.querySelector('.CodeMirror-hints, .ts-dropdown:not([hidden])[style*="block"]')) return;
				e.preventDefault(); e.stopPropagation();
				close();
			}, true);
			if (auto !== true && first === 'cols') { var f = pane.querySelector('.nuBsqTab.active'); if (f) f.focus(); }
		}
		btn.addEventListener('click', function () { open(false, 'sql'); });
		var colsBtn = document.getElementById('nuBrowseColsEdit');                  // Edit columns… = the pane on its Columns tab
		if (colsBtn) colsBtn.addEventListener('click', function () { open(false, 'cols'); });
		[['nuBrowsePhpEdit', 'php'], ['nuBrowseJsEdit', 'js']].forEach(function (x) {   // PHP Before Browse and the list's JavaScript
			var e = document.getElementById(x[0]);
			if (e) e.addEventListener('click', function () { open(false, x[1]); });
		});
		// Esc with the focus on the page (not in a field, a menu or a dialogue) closes the pane as well
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape' || !pane || e.defaultPrevented || document.querySelector('.modal.show, .dropdown-menu.show')) return;
			var t = e.target;
			if (t && t.closest && (t.closest('input, textarea, select, [contenteditable]') || pane.contains(t))) return;
			e.preventDefault();
			close();
		});
		N.browseSqlOpen = function () { open(); };                         // Ctrl+Shift+B and the ⋮ entry Browse SQL
		$('<span class="ms-auto ps-3 small text-body-secondary nuBsqKey"></span>').text(/Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌃⇧B' : 'Ctrl+Shift+B').appendTo(btn);
		if (pinned()) open(true);
		window.addEventListener('resize', size);
		window.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
	})();

	// where a Save of the side panels goes: a small label in their title – Live / Draft (rev n) / Not published yet /
	// Live, draft open / Draft needed – with the rule as tooltip (NuFormDraft::saveTarget)
	function saveTargetBadge() {
		var t = N.save_target;
		if (!t) return null;
		var ic = {live: 'bi-broadcast', draft: 'bi-pencil-square', unpub: 'bi-file-earmark', open: 'bi-exclamation-triangle', need: 'bi-lock'}[t.mode] || 'bi-broadcast';
		return $('<span class="nuSaveTarget" tabindex="0" role="note" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide"></span>')
			.addClass('nuSaveTarget-' + t.mode).attr({title: t.tip, 'aria-label': t.label + ' – ' + t.tip, 'data-mode': t.mode})
			.append($('<i class="bi" aria-hidden="true"></i>').addClass(ic), document.createTextNode(t.label));
	}
	function saveTargetTip(el) { if (el && window.nuBs) try { nuBs.tooltip(el); } catch (x) { /* no tooltip */ } }
	// The edit form's code in a side pane (the owner picked mockup B of the canvas "Edit form code pane"): the parts of design
	// mode's Code tab as a grouped list on the left (a dot = has code; the list's parts greyed at the end), one editor per part made when
	// it is first shown, all changes saved in ONE builder API form.save (fields + php) and the page reloaded. Design ▾ → Code, Ctrl+Shift+B.
	(function () {
		var C = N.code_pane, btn = document.getElementById('nuEditCodeEdit');
		if (!C || !btn || !C.items || !C.items.length) return;
		var T = C.m, pane = null, ED = {}, LAST = 'nunext-ecp-item', WKEY = 'nunext-ecp-w', WDEF = 700;
		var MODE = {JS: 'javascript', CSS: 'css', PHP: 'application/x-httpd-php-open', SQL: 'text/x-sql'};
		var item = function (k) { return C.items.filter(function (x) { return x[0] === k; })[0]; };
		var val = function (k) { return ED[k] ? ED[k].getValue() : item(k)[4]; };
		var dirtyKeys = function () { return C.items.filter(function (x) { return ED[x[0]] && ED[x[0]].getValue() !== x[4]; }).map(function (x) { return x[0]; }); };
		var cur = function () { var b = pane && pane.querySelector('.nuEcpItem.active'); return b ? b.getAttribute('data-key') : ''; };
		function post(data) {
			return NuUtil.post(N.base + '?p=builderapi', $.extend({action: 'form.save', form_id: N.form.id}, N.draft ? {draft_id: N.draft.id, rev: N.draft.rev} : {}, data)).then(function (j) {
				if (!j.ok && j.code === 'draft_open_confirm' && !data.live_ok && window.confirm(j.error)) return post($.extend({}, data, {live_ok: true}));
				if (!j.ok) throw new Error(j.error || T.error);
				return j;
			});
		}
		function marks() {
			if (!pane) return;
			pane.querySelectorAll('.nuEcpItem').forEach(function (b) {
				var has = String(val(b.getAttribute('data-key'))).trim() !== '';
				b.classList.toggle('nuEcpHas', has);
				b.setAttribute('aria-label', b.textContent + (has ? ', ' + T.code_has : ''));
			});
			pane.querySelectorAll('.nuEcpCard').forEach(function (c) {
				var n = c.querySelectorAll('.nuEcpItem').length, h = c.querySelectorAll('.nuEcpItem.nuEcpHas').length;
				c.querySelector('.nuEcpCount').textContent = h + ' / ' + n;
			});
		}
		function size() {
			if (!pane) return;
			Object.keys(ED).forEach(function (k) { var b = pane.querySelector('.nuBsqBody[data-code="' + k + '"]'); if (b && b.offsetParent) ED[k].setSize('100%', Math.max(120, b.clientHeight) + 'px'); });
		}
		function show(k, focus) {
			if (!pane || !item(k)) return;
			pane.querySelectorAll('.nuEcpItem').forEach(function (b) { var on = b.getAttribute('data-key') === k; b.classList.toggle('active',
				on); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
			pane.querySelectorAll('.nuEcpMain [data-code]').forEach(function (el) { el.hidden = el.getAttribute('data-code') !== k; });
			pane.querySelector('.nuEcpWhat').textContent = item(k)[2] + ' · ' + item(k)[1];
			nuStore.set(LAST, k);
			if (ED[k]) { ED[k].refresh(); size(); if (focus) ED[k].focus(); return; }
			loadCodeMirror().then(function () {
				if (!pane || ED[k]) return;
				var it = item(k), ta = pane.querySelector('.nuBsqBody[data-code="' + k + '"] textarea');
				ta.value = it[4];
				ED[k] = CodeMirror.fromTextArea(ta, {mode: MODE[it[2]], lineNumbers: true, lineWrapping: true, indentWithTabs: true, tabSize: 4,
					theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'default nuCmDark' : 'default'});
				ED[k].on('change', marks);
				if (window.NuCodeTools) NuCodeTools.attach(ED[k], pane.querySelector('.nuBsqTools[data-code="' + k + '"]'), {lang: it[2], db: N.db_type, codeKey: k});
				size();
				if (focus) ED[k].focus();
			});
		}
		function close(force) {
			if (!pane) return;
			if (!force && dirtyKeys().length && !window.confirm(T.unsaved)) return;
			pane.remove(); pane = null; ED = {};
			document.documentElement.classList.remove('nuBsqOpen');
			document.documentElement.style.removeProperty('--nu-bsq-w');
			btn.focus();
		}
		function save() {
			if (!pane) return;
			var keys = dirtyKeys(), sb = pane.querySelector('.nuBsqSave'), err = pane.querySelector('.nuBsqErr');
			if (!keys.length) { close(true); return; }
			var fields = {}, php = {};
			keys.forEach(function (k) { if (k.indexOf('php:') === 0) php[k.substr(4)] = val(k); else fields[k] = val(k); });
			sb.disabled = true; err.hidden = true;
			post(Object.keys(php).length ? {fields: fields, php: php} : {fields: fields}).then(function () {
				keys.forEach(function (k) { item(k)[4] = val(k); });
				close(true); window.location.reload();
			}).catch(function (e) { sb.disabled = false; err.textContent = e.message; err.hidden = false; });
		}
		// width by the splitter on the left edge, as the list's pane (own default 700 px and own stored width)
		function setW(w, keep) {
			w = Math.round(Math.max(360, Math.min(w, Math.max(360, window.innerWidth * 0.9))));
			document.documentElement.style.setProperty('--nu-bsq-w', w + 'px');
			if (keep) { try { if (w === WDEF) localStorage.removeItem(WKEY); else localStorage.setItem(WKEY, String(w)); } catch (e) { /* no storage */ } }
			size();
		}
		function splitter() {
			var saved = 0;
			try { saved = parseInt(localStorage.getItem(WKEY) || '0', 10) || 0; } catch (e) { /* no storage */ }
			var sp = document.createElement('div');
			sp.className = 'nuBsqSplit'; sp.tabIndex = 0;
			sp.setAttribute('role', 'separator'); sp.setAttribute('aria-orientation', 'vertical'); sp.setAttribute('aria-label', T.resize || '');
			if (T.resize) sp.title = T.resize;
			pane.appendChild(sp);
			setW(saved || WDEF, false);
			sp.addEventListener('pointerdown', function (e) {
				if (e.button !== 0) return;
				e.preventDefault();
				try { sp.setPointerCapture(e.pointerId); } catch (x) { /* ignore */ }
				document.documentElement.classList.add('nuBsqResizing');
				var vw = function () { return document.documentElement.clientWidth; }, off = vw() - e.clientX - pane.getBoundingClientRect().width;
				var move = function (ev) { setW(vw() - ev.clientX - off, false); };
				var up = function (ev) { sp.removeEventListener('pointermove', move); sp.removeEventListener('pointerup', up); sp.removeEventListener('pointercancel', up);
					document.documentElement.classList.remove('nuBsqResizing'); setW(vw() - ev.clientX - off, true); };
				sp.addEventListener('pointermove', move); sp.addEventListener('pointerup', up); sp.addEventListener('pointercancel', up);
			});
			sp.addEventListener('dblclick', function () { setW(WDEF, true); });
			sp.addEventListener('keydown', function (e) {
				var w = pane.getBoundingClientRect().width;
				if (e.key === 'ArrowLeft') { e.preventDefault(); setW(w + 20, true); } else if (e.key === 'ArrowRight') { e.preventDefault(); setW(w - 20,
					true); } else if (e.key === 'Home') { e.preventDefault(); setW(WDEF, true); }
			});
		}
		function open() {
			if (document.getElementById('nuBsqPane')) return;                 // the list's pane is open (a form with both on one page)
			if (pane) { var k0 = cur(); if (ED[k0]) ED[k0].focus(); return; }
			pane = document.createElement('aside');
			pane.className = 'nuBsqPane nuEcpPane';
			pane.id = 'nuEcpPane';
			pane.setAttribute('aria-label', T.title);
			var head = $('<div class="nuBsqHead"><h2 class="h6 m-0 me-auto nuEcpTitle"></h2><button type="button" class="btn-close nuBsqClose"></button></div>');
			head.find('.nuEcpTitle').text(T.title + ' – ' + (N.form.description || N.form.code || ''));
			var stb = saveTargetBadge();
			if (stb) { stb.insertAfter(head.find('.nuEcpTitle')); saveTargetTip(stb[0]); }
			head.find('.nuBsqClose').attr('aria-label', T.close).on('click', function () { close(); });
			var list = $('<div class="nuEcpList" role="tablist" aria-orientation="vertical"></div>').attr('aria-label', T.title), main = $('<div class="nuEcpMain"></div>');
			var grp = '', card = null;
			C.items.forEach(function (it) {
				// (the owner picked mockup B of the canvas "Code pane sections"): each group a small card – tinted heading with the
				// number of its parts that hold code; the list's card is dashed (its parts act on the list only)
				if (it[3] !== grp) {
					grp = it[3];
					card = $('<div class="nuEcpCard" role="presentation"></div>').attr('data-grp', grp)
						.append($('<div class="nuEcpGroup"></div>').append($('<span></span>').text(T.groups[grp] || grp), '<span class="nuEcpCount"></span>'));
					list.append(card);
				}
				card.append($('<button type="button" class="nuEcpItem" role="tab"></button>').attr({'data-key': it[0], 'data-grp': it[3]}).text(it[1]));
				main.append($('<div class="nuBsqTools" hidden></div>').attr('data-code', it[0]));
				main.append($('<div class="nuBsqBody" role="tabpanel" hidden><textarea></textarea></div>').attr('data-code', it[0]));
			});
			main.prepend('<div class="nuEcpWhat small text-body-secondary"></div>');
			var foot = $('<div class="nuBsqFoot"><span class="small text-body-secondary nuBsqHint"></span><button type="button" class="btn btn-outline-secondary btn-sm nuBsqCancel"></button><button type="button" class="btn btn-primary btn-sm nuBsqSave"><i class="bi bi-floppy me-1" aria-hidden="true"></i></button></div>');
			foot.find('.nuBsqHint').text(T.hint);
			foot.find('.nuBsqCancel').text(T.close).on('click', function () { close(); });
			foot.find('.nuBsqSave').append(document.createTextNode(T.save)).on('click', save);
			$(pane).append(head, '<div class="alert alert-danger py-1 px-2 m-2 mb-0 small nuBsqErr" role="alert" hidden></div>', $('<div class="nuEcpBody"></div>').append(list, main), foot);
			list.on('click', '.nuEcpItem', function () { show(this.getAttribute('data-key'), true); });
			list.on('keydown', '.nuEcpItem', function (e) {
				if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return;
				var all = list.find('.nuEcpItem').toArray(), i = all.indexOf(this);
				var n = e.key === 'Home' ? 0 : e.key === 'End' ? all.length - 1 : Math.max(0, Math.min(all.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)));
				e.preventDefault(); show(all[n].getAttribute('data-key'), false); all[n].focus();
			});
			document.body.appendChild(pane);
			document.documentElement.classList.add('nuBsqOpen');
			splitter();
			marks();
			var last = '';
			try { last = localStorage.getItem(LAST) || ''; } catch (e) { /* no storage */ }
			if (!item(last)) { var withCode = C.items.filter(function (x) { return x[3] !== 'list' && String(x[4]).trim() !== ''; })[0]; last = (withCode || C.items[0])[0]; }
			show(last, true);
			pane.addEventListener('keydown', function (e) {
				if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); e.stopPropagation(); save(); }
			});
			pane.addEventListener('keydown', function (e) {            // Esc also from inside an editor (as the list's pane)
				if (e.key !== 'Escape' || document.querySelector('.modal.show')) return;
				if (pane.querySelector('.cm-panel.cm-search, .cm-tooltip-autocomplete, .dropdown-menu.show') || document.querySelector('.CodeMirror-hints')) return;
				e.preventDefault(); e.stopPropagation(); close();
			}, true);
		}
		btn.addEventListener('click', function () { open(); });
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape' || !pane || e.defaultPrevented || document.querySelector('.modal.show, .dropdown-menu.show')) return;
			var t = e.target;
			if (t && t.closest && (t.closest('input, textarea, select, [contenteditable]') || pane.contains(t))) return;
			e.preventDefault(); close();
		});
		N.codePaneOpen = function () { open(); };                          // Ctrl+Shift+B on edit forms
		$('<span class="ms-auto ps-3 small text-body-secondary nuBsqKey"></span>').text(/Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌃⇧B' : 'Ctrl+Shift+B').appendTo(btn);
		window.addEventListener('resize', size);
		window.addEventListener('beforeunload', function (e) { if (pane && dirtyKeys().length) { e.preventDefault(); e.returnValue = ''; } });
	})();

	// Form info panel: description and code are changed right in the panel (pencil → field, Enter / Save, Esc / Cancel),
	// saved with the builder API's form.save (the previewed draft when one is open; a live change while a draft is open is confirmed)
	(function () {
		function texts(el) {
			var h = (el && el.closest('[data-nu-fi-texts]')) || g('nuFormInfo');
			try { return JSON.parse(h.getAttribute('data-nu-fi-texts')) || {}; } catch (e) { return {}; }
		}
		function post(fields, live) {
			return NuUtil.post(N.base + '?p=builderapi',
				$.extend({action: 'form.save', form_id: N.form.id, fields: fields}, N.draft ? {draft_id: N.draft.id, rev: N.draft.rev} : {}, live ? {live_ok: true} : {})).then(function (j) {
				if (!j.ok && j.code === 'draft_open_confirm' && !live && window.confirm(j.error)) return post(fields, true);
				if (j.ok && j.draft && N.draft) N.draft = j.draft;
				return j;
			});
		}
		function edit(btn) {
			var row = btn.closest('.nuFormInfoRow'), dd = row && row.querySelector('dd'), val = dd && dd.querySelector('.nuFiVal');
			if (!val || row.classList.contains('nuFiEditing')) return;
			var T = texts(btn), old = btn.getAttribute('data-nu-fi-value') || '', field = btn.getAttribute('data-nu-fi-field');
			var box = $('<div class="nuFiEditBox"><input type="text" class="form-control form-control-sm nuFiInput" maxlength="300">'
				+ '<button type="button" class="btn btn-sm btn-primary nuFiSave"></button><button type="button" class="btn btn-sm btn-outline-secondary nuFiCancel"></button>'
				+ '<div class="invalid-feedback d-block nuFiErr" hidden></div></div>');
			box.find('.nuFiSave').text(T.save || 'Save');
			box.find('.nuFiCancel').text(T.cancel || 'Cancel');
			var inp = box.find('input').val(old).attr('aria-label', (row.querySelector('dt') || {}).textContent || '');
			row.classList.add('nuFiEditing');
			val.hidden = true;
			box.appendTo(dd);
			inp.trigger('focus').trigger('select');
			function done() { box.remove(); val.hidden = false; row.classList.remove('nuFiEditing'); btn.focus(); }
			function fail(msg) { box.find('.nuFiErr').text(msg).prop('hidden', false); inp.addClass('is-invalid').trigger('focus'); box.find('button').prop('disabled', false); }
			function save() {
				var v = String(inp.val()).trim();
				if (v === old) { done(); return; }
				if (v === '') { fail(T.required || ''); return; }
				box.find('button').prop('disabled', true);
				var f = {}; f[field] = v;
				post(f).then(function (j) {
					if (!j.ok) { fail(j.error || 'Error'); return; }
					var code = val.querySelector('code');
					(code || val).textContent = v;
					btn.setAttribute('data-nu-fi-value', v);
					var cp = row.querySelector('.nuFormInfoCopy');
					if (cp) cp.setAttribute('data-nu-copy-text', v);
					var all = document.querySelector('#nuFormInfoCopyAll'), lbl = (row.querySelector('dt') || {}).textContent || '';
					if (all && lbl) all.setAttribute('data-nu-copy-text', all.getAttribute('data-nu-copy-text').split('\n').map(function (l) { return l.indexOf(lbl + ': ') === 0 ? lbl + ': ' + v : l; }).join('\n'));
					if (field === 'sfo_code') N.form.code = v;
					if (field === 'sfo_description') N.form.description = v;
					done();
					row.classList.add('nuFiSavedFlash');
					btn.setAttribute('title', T.saved || 'Saved');
					setTimeout(function () { row.classList.remove('nuFiSavedFlash'); }, 1600);
				}).catch(function (e) { fail(e.message); });
			}
			box.find('.nuFiSave').on('click', function (e) { e.stopPropagation(); save(); });
			box.find('.nuFiCancel').on('click', function (e) { e.stopPropagation(); done(); });
			inp.on('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); save(); }
			});
			box.on('click', function (e) { e.stopPropagation(); });
		}
		// Esc in the field cancels it only – Bootstrap's dropdown listens in the capture phase on document, so this one sits on window
		window.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape' || !e.target.classList || !e.target.classList.contains('nuFiInput')) return;
			e.preventDefault(); e.stopPropagation();
			var c = e.target.parentNode.querySelector('.nuFiCancel');
			if (c) c.click();
		}, true);
		document.addEventListener('click', function (e) {
			var b = e.target.closest && e.target.closest('.nuFiChange');
			if (!b) return;
			e.preventDefault();
			edit(b);
		});
	})();

	api('nuOpenAce', function (lang, id) {
		var el = typeof id === 'string' ? g(id) : id;
		if (!el || N.codeEditorOpen) return;          // form code may bind its own dblclick as well: open once
		N.codeEditorOpen = true;
		id = el.id;
		var L = String(lang || 'JS').toUpperCase();
		var mode = {JS: 'javascript', JAVASCRIPT: 'javascript', PHP: 'application/x-httpd-php-open', SQL: 'text/x-sql', CSS: 'css', HTML: 'htmlmixed'}[L] || null;
		loadCodeMirror().then(function () {
			$('#nuCodeModal').remove();
			var ro = el.readOnly || el.disabled;
			var m = $('<div class="modal fade" id="nuCodeModal" tabindex="-1" aria-hidden="true" aria-labelledby="nuCodeModalTitle"><div class="modal-dialog modal-xl modal-fullscreen-lg-down nuCodeDialog"><div class="modal-content">'
				+ '<div class="modal-header py-2 gap-2 flex-wrap"><h2 class="modal-title fs-6" id="nuCodeModalTitle"></h2><span class="nuCodeToolsHost ms-3"></span><span class="nuCodeHash me-auto"></span><span class="badge text-bg-secondary nuCodeLang"></span>'
				+ '<button type="button" class="btn-close ms-1" data-bs-dismiss="modal"></button></div>'
				+ '<div class="modal-body p-0"><textarea></textarea></div>'
				+ '<div class="modal-footer py-2"><span class="small text-body-secondary me-auto nuCodeHint"></span><button type="button" class="btn btn-outline-secondary btn-sm nuCodeCancel"></button><button type="button" class="btn btn-primary btn-sm nuCodeApply"></button></div>'
				+ '</div></div></div>').appendTo('body');
			m.find('.modal-title').text(labelText(id) || id);
			m.find('.nuCodeLang').text(L === 'JAVASCRIPT' ? 'JS' : L);
			var hashHost = m.find('.nuCodeHash')[0];
			m.find('.btn-close').attr('aria-label', M.cancel || 'Cancel');
			m.find('.nuCodeCancel').text(M.cancel || 'Cancel');
			m.find('.nuCodeApply').text(M.ok || 'OK').toggle(!ro);
			m.find('.nuCodeHint').text(ro ? (M.code_readonly || '') : (M.code_hint || 'Ctrl+Enter = OK · Esc = cancel'));
			var cm = CodeMirror.fromTextArea(m.find('textarea')[0], {mode: mode, lineNumbers: true, lineWrapping: true, readOnly: ro, indentWithTabs: true, tabSize: 4,
				theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'default nuCmDark' : 'default'});
			cm.setValue(el.value);
			cm.clearHistory();
			// "#" menu: hash variables are replaced in SQL, PHP and HTML (not in JavaScript)
			// full screen button in the toolbar: the modal becomes full screen
			var fullscreen = function (on) { m.find('.modal-dialog').toggleClass('modal-fullscreen', on); size(); return on; };
			if (window.NuCodeTools) NuCodeTools.attach(cm, m.find('.nuCodeToolsHost')[0],
				{lang: L, db: N.db_type, readOnly: ro, fullscreen: fullscreen, scope: /^set_(header|style)$/.test(el.id || '') ? 1 : undefined});
			if (!ro && window.NuHashVars && N.hashCatalog && (L === 'SQL' || L === 'PHP' || L === 'HTML')) {
				NuHashVars.attach(cm, hashHost, {catalog: N.hashCatalog, globals: N.globals, fields: function () { return hashFieldsFor(el); }});
			}
			var start = el.value, applied = false;
			var apply = function () {
				if (!ro && el.value !== cm.getValue()) { el.value = cm.getValue(); markEdited(el); $(el).trigger('change'); }
				applied = true;
				nuBs.modal(m[0]).hide();
			};
			var size = function () { cm.setSize(null, m.find('.modal-dialog').hasClass('modal-fullscreen') ? 'calc(100vh - 8rem)' : '70vh'); cm.refresh(); };
			var cancel = function () { nuBs.modal(m[0]).hide(); };
			cm.setOption('extraKeys', {'Ctrl-Enter': apply, 'Cmd-Enter': apply, 'Ctrl-S': apply, 'Cmd-S': apply, Esc: cancel});
			m.find('.nuCodeApply').on('click', apply);
			m.find('.nuCodeCancel').on('click', cancel);
			m.on('hide.bs.modal', function (e) {   // closing with unsaved edits asks first
				if (applied || ro || cm.getValue() === start) return;
				if (!window.confirm(M.code_discard || 'Discard the changes?')) e.preventDefault();
			});
			m.on('shown.bs.modal', function () { size(); cm.focus(); });
			m.on('hidden.bs.modal', function () { m.remove(); N.codeEditorOpen = false; try { el.focus(); } catch (x) { /* ignore */ } });
			nuBs.modal(m[0], {backdrop: 'static'}).show();
		}, function () { N.codeEditorOpen = false; reportError('CodeMirror could not be loaded', 'nuOpenAce'); });
	});

	/** Fields of the form the edited code belongs to: nuBuilder's form / object / PHP forms edit code of another form. */
	function hashFieldsFor(el) {
		var v = function (f) { var x = g((el.getAttribute('data-nu-prefix') || '') + f) || g(f); return x ? String(x.value || '') : ''; };
		var target = N.form.id;
		if (N.form.id === 'nuform') target = N.record_id;
		else if (N.form.id === 'nuobject') target = v('sob_all_zzzzsys_form_id');
		else if (N.form.id === 'nuphp') target = v('sph_zzzzsys_form_id') || String(N.record_id || '').replace(/_(BB|BE|BS|AS|BD|AD)$/, '');
		if (!target || target === '-1') return Promise.resolve([]);
		return call('hashfields', {target: target}).then(function (r) { return r && r.ok ? r.fields : []; });
	}

	// ---- code textareas: double-click opens the code editor (nuBuilder: class js / sql / php / html / css, nuACEInitDblClickHandlers)
	var CODE_FIELDS = {
		PHP: ['sph_php'],
		SQL: ['sfo_browse_sql', 'sob_select_sql', 'sob_display_sql', 'sse_sql'],
		JS: ['sfo_javascript', 'sfo_browse_javascript', 'sfo_edit_javascript', 'sev_javascript', 'sph_javascript', 'sob_input_javascript', 'sob_lookup_javascript', 'sob_html_javascript'],
		HTML: ['set_header', 'sob_html_code'],
		CSS: ['sfo_style', 'set_style', 'itm_css']
	};
	var SNIPPET_LANG = {javascript: 'JS', php: 'PHP', sql: 'SQL', css: 'CSS', html: 'HTML'};
	function codeLanguage(el) {
		if (!el || el.tagName !== 'TEXTAREA' || el.classList.contains('nuEditor')) return null;
		var f = el.getAttribute('data-nu-field') || el.id;
		if (f === 'cot_source_code') {                // code snippets: the language field decides (Forte's form code adds class js to it)
			var l = g((el.getAttribute('data-nu-prefix') || '') + 'cot_language');
			return SNIPPET_LANG[String(l ? l.value : '').toLowerCase()] || 'Text';
		}
		var cls = ['js', 'sql', 'php', 'html', 'css'].filter(function (c) { return el.classList.contains(c); })[0];
		if (cls) return cls.toUpperCase();
		for (var k in CODE_FIELDS) if (CODE_FIELDS[k].indexOf(f) >= 0) return k;
		return null;
	}
	function markCodeFields(root) {
		$(root || document).find('textarea').each(function () {
			if (!codeLanguage(this) || this.classList.contains('nuCodeField')) return;
			this.classList.add('nuCodeField');
			if (!this.title) this.title = M.code_dblclick || 'Double-click to edit the code';
		});
	}
	$(document).on('dblclick', 'textarea', function () {
		var lang = codeLanguage(this);
		if (lang) nuOpenAce(lang, this.id);
	});
	api('nuACEInitDblClickHandlers', function () { markCodeFields(document); });

	// inline code editor (the owner's wish): code snippets' Source code is edited in place with CodeMirror and its toolbar
	// (undo, search, comment, format, full screen …) instead of a plain textarea; the language field switches the highlighting.
	// The textarea stays (hidden) and gets every change, so saving, the leave guard and nuBuilder Forte's code work as before.
	var INLINE_CODE = ['cot_source_code'];
	var CM_MODE = {JS: 'javascript', PHP: {name: 'php', startOpen: true}, SQL: 'text/x-sql', CSS: 'css', HTML: 'htmlmixed'};
	function inlineCodeFields() {
		var tas = INLINE_CODE.map(g).filter(function (t) { return t && t.tagName === 'TEXTAREA' && !t.nuInlineCM && !t.closest('template'); });
		if (!tas.length) return;
		loadCodeMirror().then(function () { tas.forEach(inlineCode); }, function () { /* the textarea stays */ });
	}
	function inlineCode(ta) {
		if (ta.nuInlineCM || !window.CodeMirror) return;
		var lang = codeLanguage(ta) || 'JS', w = ta.offsetWidth, h = ta.offsetHeight;
		var bar = document.createElement('div');
		bar.className = 'nuInlineCodeBar';
		ta.parentNode.insertBefore(bar, ta);
		var cm = CodeMirror.fromTextArea(ta, {mode: CM_MODE[lang] || null, lineNumbers: true, lineWrapping: true, matchBrackets: true, autoCloseBrackets: true,
			indentWithTabs: true, tabSize: 4, indentUnit: 4, readOnly: ta.readOnly || ta.disabled});
		ta.nuInlineCM = cm;
		var wrap = cm.getWrapperElement();
		wrap.classList.add('nuInlineCode');
		bar.style.width = w + 'px';
		cm.setSize(w, Math.max(80, h - 34));
		cm.on('change', function () { cm.save(); if (!ta.readOnly && !ta.disabled) { markEdited(ta); $(ta).trigger('input'); } });
		var tools = null;
		var attach = function () {
			if (tools && tools.nuDetach) tools.nuDetach();
			bar.textContent = '';
			lang = codeLanguage(ta) || 'JS';
			cm.setOption('mode', CM_MODE[lang] || null);
			if (window.NuCodeTools) tools = NuCodeTools.attach(cm, bar, {lang: lang, db: N.db_type, readOnly: ta.readOnly || ta.disabled});
		};
		attach();
		var langEl = g((ta.getAttribute('data-nu-prefix') || '') + 'cot_language');
		if (langEl) $(langEl).on('change', attach);
		// a value set by code (nuSetValue, jQuery .val() + change) reaches the editor
		$(ta).on('change.nuInline', function () { if (cm.getValue() !== ta.value) { cm.setValue(ta.value); cm.clearHistory(); } });
	}
	N.inlineCode = inlineCode;
	N.markCodeFields = markCodeFields;

