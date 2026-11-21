	// =================================================================== small helpers nuBuilder code uses
	api('nuPad2', function (n, c) { return String(n).padStart(2, c === undefined || c === null ? '0' : String(c)); });
	api('nuPad3', function (n, c) { return String(n).padStart(3, c === undefined || c === null ? '0' : String(c)); });
	api('nuPad4', function (n, c) { return String(n).padStart(4, c === undefined || c === null ? '0' : String(c)); });
	// nuBuilder: texts in the user's language (zzzzsys_translate, sent with the page); "|text" is never translated
	function trOne(s) {
		if (s === null || s === undefined) return '';
		s = String(s);
		if (s.charAt(0) === '|') return s.substring(1);
		var d = N.translations || {};
		return Object.prototype.hasOwnProperty.call(d, s) ? d[s] : s;
	}
	api('nuTranslate', function (s) { return Array.isArray(s) ? s.map(trOne) : trOne(s); });
	api('nuTranslateToEnglish', function (s) {
		if (s === null || s === undefined) return '';
		s = String(s);
		if (s.charAt(0) === '|') return s.substring(1);
		var d = N.translations || {};
		for (var k in d) if (Object.prototype.hasOwnProperty.call(d, k) && d[k] === s) return k;
		return s;
	});
	api('nuDevMode', function () { return !!N.dev_mode; });
	api('nuEventName', function (e) {                    // nuBuilder: 'BE' → 'Before Edit' (default: the last 2 letters of the record id)
		e = e || String(N.record_id || '').slice(-2);
		return {BB: 'Before Browse', BE: 'Before Edit', BS: 'Before Save', AS: 'After Save', BD: 'Before Delete', AD: 'After Delete', AB: 'After Browse'}[e];
	});
	// nuBuilder Forte: false inside an iframe / popup and – unless ignoreOpener – in a window opened by another form window
	api('nuMainForm', function (ignoreOpener) {
		if (N.iframe || N.lookup) return false;
		if (!ignoreOpener && window.opener) { try { if (window.opener.nuNext && window.opener !== window) return false; } catch (x) { /* other origin */ } }
		return true;
	});
	api('nuFormType', function () { return N.mode === 'browse' ? 'browse' : 'edit'; });
	api('nuRecordId', function () { return N.record_id; });
	api('nuFormId', function () { return N.form.id; });
	// nuBuilder Forte: -1 = not an edit form, 0 = opened (not saved yet), n = saved n times since it was opened
	var timesSaved = (function () {
		if (N.mode !== 'edit' || !N.form || N.form.type === 'launch') return -1;
		var k = 'nunext-saved:' + N.form.id, v = null, rec = String(N.record_id || '');
		try { v = JSON.parse(sessionStorage.getItem(k) || 'null'); } catch (x) { v = null; }
		var n = N.saved && v && (v.r === rec || v.r === '-1') ? v.n + 1 : (N.saved ? 1 : 0);
		try { sessionStorage.setItem(k, JSON.stringify({r: rec, n: n})); } catch (x) { /* private mode */ }
		return n;
	})();
	api('nuHasBeenSaved', function () { return timesSaved; });
	// as nuBuilder Forte 4.9: marks the record as edited (red Save, leave guard) – it was a read-only check here; nuIsSaved() reads the state
	api('nuHasBeenEdited', function () {
		if (!N.edited) callHook('nuOnSetSaved', false);
		N.edited = true;
		$('#nuSaveButton').addClass('nuSaveEdited').prop('disabled', false);
		return true;
	});
	api('nuIsSaved', function () { return !N.edited; });
	api('nuAccessLevelCode', function () { return N.user.access_level_code || ''; });
	// main + active additional access levels: [{id, code, description, group, main, valid_until}], [] for the globeadmin – a copy
	api('nuNextAccessLevels', function () { return (N.user.access_levels || []).map(function (l) { return Object.assign({}, l); }); });
	api('nuGlobalAccess', function () { return !!N.user.globeadmin; });
	// Short notice at the bottom right (save / delete / clone). type: success | danger | warning | info
	// design mode (review H1): messages of the form's own code do not cover the design panel – they are collected for the bar's
	// "Messages of the form" button (assets/js/nu/nudesign.js, event nu:formmsg)
	N.formMsgs = [];
	function designQuiet(kind, text) {
		if (!N.design) return false;
		N.formMsgs.push({kind: kind, text: String(text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()});
		try { document.dispatchEvent(new CustomEvent('nu:formmsg')); } catch (x) { /* old browser */ }
		return true;
	}
	/** "Record deleted · Undo": the record is in the recycle bin – Undo puts it back and opens it. */
	function undoToast(msg, id) {
		nuToast(msg + ' ' + (M.rb_in_bin || ''), 'success', 10000, {action: {label: M.rb_undo || 'Undo', fn: function (t) {
			var fd = new FormData();
			fd.append('action', 'restore'); fd.append('id', id); fd.append('_csrf', N.csrf);
			fetch(N.base + '?p=recyclebin', {method: 'POST', credentials: 'same-origin', body: fd, headers: {'Accept': 'application/json'}})
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j && j.ok) { nuStore.session.set('nuNextToast', JSON.stringify([j.message, 'success'])); window.location.href = j.url; }
					else { nuToast((j && j.message) || 'Error', 'danger', 6000); }
				}, function () { nuToast('Error', 'danger', 6000); });
			try { nuBs.toast(t).hide(); } catch (x) { t.remove(); }
		}}});
	}
	api('nuToast', function (msg, type, ms, opt) {
		if (designQuiet('toast', msg)) return;
		var box = g('nuToasts');
		if (!box) { box = document.createElement('div'); box.id = 'nuToasts'; box.className = 'toast-container position-fixed bottom-0 end-0 p-3'; document.body.appendChild(box); }
		var t = document.createElement('div');
		t.className = 'toast align-items-center border-0 text-bg-' + (type || 'success');
		t.setAttribute('role', type === 'danger' ? 'alert' : 'status'); t.setAttribute('aria-live', 'polite'); t.setAttribute('aria-atomic', 'true');
		t.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
		t.querySelector('.toast-body').textContent = msg;
		if (opt && opt.action && typeof opt.action.fn === 'function') {    // a button in the toast (Undo of a delete)
			var ab = document.createElement('button');
			ab.type = 'button'; ab.className = 'btn btn-sm btn-light ms-2 nuToastAct'; ab.textContent = opt.action.label || 'OK';
			ab.addEventListener('click', function () { ab.disabled = true; opt.action.fn(t); });
			t.querySelector('.toast-body').appendChild(ab);
		}
		box.appendChild(t);
		t.addEventListener('hidden.bs.toast', function () { t.remove(); });
		if (nuBs.ready()) nuBs.toast(t, {delay: ms || 3000}).show();
		else { t.classList.add('show'); setTimeout(function () { t.remove(); }, ms || 3000); }
	});
	// nuBuilder: nuVendorLogin('PMA' | 'TFM', table) – phpMyAdmin / the File Manager in a new tab (globeadmin only); also 'ADM' (Adminer) and 'DB' (the primary database tool)
	// 'TFM' opens the File Manager page nuFiles (Tiny File Manager was removed)
	api('nuVendorLogin', function (appId, table) {
		if (!N.user.globeadmin) return;
		if (String(appId).toUpperCase() === 'TFM') { window.open(N.base + '?p=filemanager', '_blank', 'noopener'); return; }
		var f = document.createElement('form');
		f.method = 'post'; f.action = 'index.php?p=vendor'; f.target = '_blank';
		[['_csrf', N.csrf], ['app', appId || 'PMA'], ['table', table === undefined ? (N.form.table || '') : (table || '')]].forEach(function (kv) {
			var i = document.createElement('input'); i.type = 'hidden'; i.name = kv[0]; i.value = kv[1]; f.appendChild(i);
		});
		document.body.appendChild(f); f.submit(); f.remove();
	});
	api('nuUserName', function () { return N.user.name || ''; });   // sus_name; '' for the globeadmin, as nuBuilder Forte
	api('nuUserLanguage', function () { return N.user.language; });
	// custom user field of the signed-in user; null when there is no such field
	api('nuNextUserField', function (code) { var f = N.user.fields || {}; var k = String(code || '').toLowerCase(); return Object.prototype.hasOwnProperty.call(f, k) ? f[k] : null; });
	api('nuCurrentDateTime', function (fmt) {
		var d = new Date();
		var s = d.getFullYear() + '-' + nuPad2(d.getMonth() + 1) + '-' + nuPad2(d.getDate()) + ' ' + nuPad2(d.getHours()) + ':' + nuPad2(d.getMinutes()) + ':' + nuPad2(d.getSeconds());
		// as nuBuilder Forte: a format without the type prefix is a date format ('dd.mm.yyyy hh:nn' = 'D|dd.mm.yyyy hh:nn')
		return fmt === undefined || fmt === null || fmt === '' ? s : nuFORM.addFormatting(s, /^[A-Z]\|/.test(fmt) ? fmt : 'D|' + fmt);
	});
	/** nuBuilder Forte's breadcrumb list properties: browse_rows = the page's rows as [pk, value 0, value 1 …] (unformatted), browse_columns
	 *  = {title, display, align, width} per column, browse_filtered_rows = the number of records of the list. Read from the drawn table. */
	function browseProps() {
		if (!N.browse) return {};
		var cols = [], rows = [];
		$('#nuBrowseTable thead th[data-nu-col]').each(function () {
			cols[Number(this.getAttribute('data-nu-col'))] = {title: this.textContent.trim(), display: '',
				align: (this.className.match(/text-(start|center|end)/) || ['', 'start'])[1].replace('start', 'left').replace('end', 'right'),
				width: this.style.display === 'none' ? 0 : parseInt(this.style.width, 10) || 0, id: this.getAttribute('data-nu-browse-id') || ''};
		});
		$('#nuRECORD tr.nuBrowseRow').each(function (i) {
			var raw = N.browse.rows && N.browse.rows[i];                          // the values as the browse SQL returned them, as Forte
			if (raw && String(raw[0]) === String(this.getAttribute('data-nu-primary-key') || '')) { rows.push(raw.slice()); return; }
			var r = [this.getAttribute('data-nu-primary-key') || ''];
			$(this).find('div.nuCell').each(function () { r[Number(this.getAttribute('data-nu-column')) + 1] = this.textContent.trim(); });
			rows.push(r);
		});
		return {browse_columns: cols, browse_rows: rows, browse_filtered_rows: Number(N.browse.total || 0), browse_table_id: '',
			pages: Number(N.browse.pages || 1), page_number: Math.max(0, Number(N.browse.page || 1) - 1)};   // Forte: page_number from 0
	}
	// the run filter of the opening run button / nuForm(…, filter) (sob_run_filter → ?rf=) – nuBuilder Forte's breadcrumb property "filter"
	// nuFORM.getCurrent().browse_sql: the list's SQL with its hash variables filled in, as nuBuilder Forte (pages/nuform.php);
	// a #name# without a value stays in it as text
	function browseSqlLeft() { return N.browse ? (typeof N.browse.sql === 'string' ? N.browse.sql : (N.browse.unset ? N.browse.unset.join(' ') : '')) : ''; }
	function runFilter() { try { return new URL(window.location.href).searchParams.get('rf') || ''; } catch (x) { return ''; } }
	// nuBuilder Forte keeps the page: methods the form's code adds to built-in prototypes stay defined from the list. Each record is a
	// new page here, so such top-level definitions (Date.prototype.valid = function () {…}) run before the form's code (pages/nuform.php)
	window.nuNextHoistPrototypes = function (code) {
		if (!window.acorn || typeof code !== 'string') return;
		var ast;
		try { ast = window.acorn.parse(code, {ecmaVersion: 'latest', sourceType: 'script', allowReturnOutsideFunction: true}); } catch (x) { return; }
		ast.body.forEach(function (st) {
			var a = st.type === 'ExpressionStatement' ? st.expression : null;
			if (!a || a.type !== 'AssignmentExpression' || a.operator !== '=' || a.left.type !== 'MemberExpression' || a.left.computed) return;
			var o = a.left.object;
			if (o.type !== 'MemberExpression' || o.computed || o.object.type !== 'Identifier' || o.property.name !== 'prototype') return;
			if (['FunctionExpression', 'ArrowFunctionExpression'].indexOf(a.right.type) < 0) return;
			var ctor = window[o.object.name], name = a.left.property.name;
			if (typeof ctor !== 'function' || !ctor.prototype || name in ctor.prototype) return;   // never replace what exists
			try { (0, eval)(code.slice(st.start, st.end)); } catch (x) { /* the form's own run reports it */ }   // eslint-disable-line no-eval
		});
	};
	/** nuBuilder Forte's breadcrumb of the page (nuformclass.js addBreadcrumb + nuform.js nuSetFormProperties): the same keys
	 *  and value types as Forte – in an edit form the list keys are 0 / [] as there, tab_start the selected tab as text. N.crumb comes from
	 *  pages/nuform.php (list settings, raw columns). Properties set with nuSetProperty / nuFORM.setProperty win, the form / record ids not. */
	function crumbBase() {
		var C = N.crumb || {}, br = !!N.browse, bp = br ? browseProps() : {}, cols = br ? (C.columns || []) : [];
		var widths = cols.map(function (c, i) {
			var th = document.querySelector('#nuBrowseTable thead th[data-nu-col="' + i + '"]');
			if (!th || !c.width) return 0;
			if (th.style.display === 'none') return 0;
			var w = parseInt(th.style.width, 10);
			return isNaN(w) ? c.width : Math.max(0, w - (C.pad || 0));
		});
		var tab = br ? null : nuSelectedTabNumber();
		return {
			form_id: N.form.id, redirect_form_id: C.redirect_form_id || N.form.id, record_id: N.record_id, title: N.title || N.form.description,
			call_type: '', column_widths: br ? widths : 0, filter: runFilter(), forms: [], iframe: N.iframe ? 1 : 0, lookup_id: C.lookup_id || '',
			object_id: '1', page_number: br ? bp.page_number : 0, password: '', rows: C.rows || 20, row_height: C.row_height || 18,
			search: br ? N.browse.q || '' : '', session_id: C.session_id || '', nosearch_columns: br ? (N.browse.nsc || []).map(Number) : [],
			sort: br && N.browse.sort !== null && N.browse.sort !== '' && N.browse.sort !== undefined ? String(N.browse.sort) : '-1',
			// Forte: 'desc' while the list is not sorted by a column
			sort_direction: br && N.browse.sort !== null && N.browse.sort !== '' && N.browse.sort !== undefined ? N.browse.dir || 'desc' : 'desc', subforms: 0,
			tab_start: br ? [] : [{prefix: '', tabNumber: tab === null || tab === undefined ? '0' : String(tab)}],
			username: '', user_id: N.user.id, refreshed: 0,
			browse_target: C.browse_target || '', redirect_other_form_id: C.redirect_other_form_id || '',
			browse_columns: br ? cols.map(function (c) { return $.extend({}, c); }) : [], browse_sql: br ? browseSqlLeft() : 0,
			browse_rows: br ? bp.browse_rows : 0, browse_table_id: C.browse_table_id || '', browse_filtered_rows: br ? bp.browse_filtered_rows : 0,
			browse_title_multiline: C.browse_title_multiline === undefined ? false : C.browse_title_multiline,
			browse_autoresize_columns: C.browse_autoresize_columns === undefined ? null : C.browse_autoresize_columns,
			mobile_view: C.mobile_view === undefined ? null : C.mobile_view, pages: br ? bp.pages : 0,
			form_code: N.form.code, form_description: N.form.description, form_group: C.form_group || '', form_type: N.form.type,
			run_code: C.run_code || '', run_description: C.run_description || '', data_mode: C.data_mode === undefined ? null : C.data_mode
		};
	}
	api('nuCurrentProperties', function () {
		var b = crumbBase();
		return $.extend({}, N.globals, b, N.props, crumbSet, {
			form_id: b.form_id, form_code: b.form_code, form_description: b.form_description, form_type: b.form_type, record_id: b.record_id,
			user_id: N.user.id, user_name: N.user.name, login_name: N.user.login,      // nuBuilder Next's own additions
			access_level_id: N.user.access_level_id, access_level_code: N.user.access_level_code, global_access: N.user.globeadmin ? '1' : '0'
		});
	});
	// nuBuilder Forte globals / helpers form code relies on: window.nuImages (the Files form fills it per row),
	// String.prototype.containsAny(array) (the Objects form's nuBeforeSave)
	if (!window.nuImages) window.nuImages = [];
	if (!String.prototype.containsAny) String.prototype.containsAny = function (arr) { for (var i = 0; i < (arr || []).length; i++) if (this.indexOf(arr[i]) > -1) return true; return false; };
	if (!String.prototype.nuCapitalise) String.prototype.nuCapitalise = function () { return this.charAt(0).toUpperCase() + this.slice(1); };
	if (!String.prototype.nuToTitleCase) String.prototype.nuToTitleCase = function () { return this.replace(/\w\S*/g, function (t) { return t.charAt(0).toUpperCase() + t.substr(1); }); };
	window.nuSESSION = window.nuSESSION || '';
	window.nuUXOptions = Object.assign({}, N.ux || {}, window.nuUXOptions || {});   // settings (nuUXOptions) – code may still change them
	window.nuLOOKUPSTATE = window.nuLOOKUPSTATE || {};
	// nuBuilder's flags of an empty browse list (nuBrowseHandleNoDataScenario): no rows at all / none for the search
	window.nuBrowseNoData = !!(N.browse && !N.browse.total && !N.browse.q);
	window.nuBrowseNoSearchResults = !!(N.browse && !N.browse.total && N.browse.q);

