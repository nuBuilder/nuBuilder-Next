/* global nuBs */
/*
 * nuBuilder Next form runtime + nuBuilder JavaScript API compatibility layer.
 *
 * Existing nuBuilder custom code (sfo_javascript, events, header functions) calls nu* functions and
 * relies on nuBuilder element IDs. This file provides those functions on window. Anything a form
 * defines itself (nuBeforeSave, nuCalculateForm, nuGetLookupCode …) overrides the defaults, because
 * the runtime always calls them through window.
 *
 * Internal event handling uses jQuery, because custom code fires events with $(…).change().
 */
(function () {
	'use strict';

	var N = window.nuNext || {};
	var $ = window.jQuery;
	var M = N.messages || {};
	N.props = N.props || {};
	N.globals = N.globals || {};
	N.locked = N.locked || [];
	var propsDirty = false;      // properties changed in the browser since the page's scope was stored on the server
	N.edited = false;
	N.jsErrors = [];

	function g(id) { return id ? document.getElementById(id) : null; }
	function arr(x) { return x === undefined || x === null ? [] : (Array.isArray(x) ? x : [x]); }
	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
	// HTML from form code (nuSetTitle, nuSetLabelText, nuMessage) without script (0.8.478, security assessment K2): a record value such
	// as <img src=x onerror=…> passed to these helpers ran as script in the next user's browser. Parsed in an inert <template> (nothing
	// loads or runs there), script-like elements, on… attributes and javascript: / vbscript: / non-image data: URLs are removed, and the
	// nodes are moved over as they are (no second parse). Formatting (<b>, <br>, <span class>, icons, links, inputs) stays.
	// nuNextSafeHtml(false) in a form's JavaScript turns it off for nuMessage only (nuBuilder code with onclick buttons in messages).
	var SAFE_DROP = {script: 1, iframe: 1, frame: 1, frameset: 1, object: 1, embed: 1, applet: 1, base: 1, meta: 1, link: 1, noscript: 1,
		template: 1, animate: 1, set: 1, animatemotion: 1, animatetransform: 1, handler: 1, portal: 1, foreignobject: 1};
	var SAFE_URL = /^(href|src|action|formaction|xlink:href|data|poster|background|lowsrc|dynsrc|ping|cite|codebase|srcset|to|from|values)$/;
	var safeMessages = true;
	function safeFrag(html) {
		var t = document.createElement('template'), dropped = 0;
		t.innerHTML = html === null || html === undefined ? '' : String(html);
		Array.prototype.slice.call(t.content.querySelectorAll('*')).forEach(function (el) {
			if (!t.content.contains(el)) return;                     // inside an element removed before
			if (SAFE_DROP[String(el.localName).toLowerCase()]) { el.remove(); dropped++; return; }
			Array.prototype.slice.call(el.attributes).forEach(function (at) {
				var n = at.name.toLowerCase(), v = String(at.value).replace(/[\u0000- \u007f-\u009f]/g, '').toLowerCase();
				if (n.indexOf('on') === 0 || n === 'srcdoc' || n === 'style' && /expression\(|url\(\s*['"]?\s*javascript:/.test(v)
					|| SAFE_URL.test(n) && (/^(javascript|vbscript):/.test(v) || /^data:/.test(v) && !/^data:image\/(png|jpe?g|gif|webp|bmp|avif);/.test(v))) {
					el.removeAttribute(at.name); dropped++;
				}
			});
		});
		if (dropped && window.console) console.warn('nuBuilder Next removed ' + dropped + ' script part(s) from HTML given to a message / title helper.');
		return t.content;
	}
	api('nuNextSafeHtml', function (on) { safeMessages = on !== false; });
	api('nuSafeHtml', function (html) { var d = document.createElement('div'); d.appendChild(safeFrag(html)); return d.innerHTML; });
	function globalEval(code) { if (code && String(code).trim() !== '') (0, eval)(String(code)); }   // eslint-disable-line no-eval
	function runSnippet(code, event, thisArg) {
		if (!code || !String(code).trim()) return;
		try { return new Function('event', code).call(thisArg || window, event || {}); }   // eslint-disable-line no-new-func
		catch (e) { reportError(e.message, 'event code'); console.error(e); }
	}
	function api(name, fn) { window[name] = fn; }
	/** Label text without the validation markers (* / key and their screen reader words). */
	function labelText(id) { return $('#label_' + id).first().clone().find('.nuMarks, .nuRequired').remove().end().text().trim(); }

	// =================================================================== error collection (shown to globeadmin)
	function reportError(msg, where) {
		N.jsErrors.push((where ? where + ': ' : '') + msg);
		var b = g('nuJsErrors');
		if (b) { b.classList.remove('d-none'); b.querySelector('span').textContent = (M.js_errors || 'JS errors') + ' (' + N.jsErrors.length + ')'; }
	}
	window.addEventListener('error', function (e) { reportError(e.message + (e.lineno ? ' (line ' + e.lineno + ')' : ''), ''); });
	window.addEventListener('unhandledrejection', function (e) { reportError(String(e.reason && e.reason.message || e.reason), 'promise'); });

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
	// as nuBuilder Forte 4.9 (0.8.950): marks the record as edited (red Save, leave guard) – it was a read-only check here; nuIsSaved() reads the state
	api('nuHasBeenEdited', function () {
		if (!N.edited) callHook('nuOnSetSaved', false);
		N.edited = true;
		$('#nuSaveButton').addClass('nuSaveEdited').prop('disabled', false);
		return true;
	});
	api('nuIsSaved', function () { return !N.edited; });
	api('nuAccessLevelCode', function () { return N.user.access_level_code || ''; });
	// main + active additional access levels (0.8.867): [{id, code, description, group, main, valid_until}], [] for the globeadmin – a copy
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
	api('nuToast', function (msg, type, ms) {
		if (designQuiet('toast', msg)) return;
		var box = g('nuToasts');
		if (!box) { box = document.createElement('div'); box.id = 'nuToasts'; box.className = 'toast-container position-fixed bottom-0 end-0 p-3'; document.body.appendChild(box); }
		var t = document.createElement('div');
		t.className = 'toast align-items-center border-0 text-bg-' + (type || 'success');
		t.setAttribute('role', type === 'danger' ? 'alert' : 'status'); t.setAttribute('aria-live', 'polite'); t.setAttribute('aria-atomic', 'true');
		t.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
		t.querySelector('.toast-body').textContent = msg;
		box.appendChild(t);
		t.addEventListener('hidden.bs.toast', function () { t.remove(); });
		if (nuBs.ready()) nuBs.toast(t, {delay: ms || 3000}).show();
		else { t.classList.add('show'); setTimeout(function () { t.remove(); }, ms || 3000); }
	});
	// nuBuilder: nuVendorLogin('PMA' | 'TFM', table) – phpMyAdmin / the File Manager in a new tab (globeadmin only); also 'ADM' (Adminer) and 'DB' (the primary database tool)
	// 'TFM' opens the File Manager page nuFiles since 0.8.479 (Tiny File Manager was removed)
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
	// custom user field of the signed-in user (0.8.454); null when there is no such field
	api('nuNextUserField', function (code) { var f = N.user.fields || {}; var k = String(code || '').toLowerCase(); return Object.prototype.hasOwnProperty.call(f, k) ? f[k] : null; });
	api('nuCurrentDateTime', function (fmt) {
		var d = new Date();
		var s = d.getFullYear() + '-' + nuPad2(d.getMonth() + 1) + '-' + nuPad2(d.getDate()) + ' ' + nuPad2(d.getHours()) + ':' + nuPad2(d.getMinutes()) + ':' + nuPad2(d.getSeconds());
		// as nuBuilder Forte: a format without the type prefix is a date format ('dd.mm.yyyy hh:nn' = 'D|dd.mm.yyyy hh:nn')
		return fmt === undefined || fmt === null || fmt === '' ? s : nuFORM.addFormatting(s, /^[A-Z]\|/.test(fmt) ? fmt : 'D|' + fmt);
	});
	/** nuBuilder Forte's breadcrumb list properties (0.8.960): browse_rows = the page's rows as [pk, value 0, value 1 …] (unformatted, 0.8.965), browse_columns
	 *  = {title, display, align, width} per column, browse_filtered_rows = the number of records of the list. Read from the drawn table. */
	function browseProps() {
		if (!N.browse) return {};
		var cols = [], rows = [];
		$('#nuBrowseTable thead th[data-nu-col]').each(function () {
			cols[Number(this.getAttribute('data-nu-col'))] = {title: this.textContent.trim(), display: '', align: (this.className.match(/text-(start|center|end)/) || ['', 'start'])[1].replace('start', 'left').replace('end', 'right'),
				width: this.style.display === 'none' ? 0 : parseInt(this.style.width, 10) || 0, id: this.getAttribute('data-nu-browse-id') || ''};
		});
		$('#nuRECORD tr.nuBrowseRow').each(function (i) {
			var raw = N.browse.rows && N.browse.rows[i];                          // the values as the browse SQL returned them (0.8.965), as Forte
			if (raw && String(raw[0]) === String(this.getAttribute('data-nu-primary-key') || '')) { rows.push(raw.slice()); return; }
			var r = [this.getAttribute('data-nu-primary-key') || ''];
			$(this).find('div.nuCell').each(function () { r[Number(this.getAttribute('data-nu-column')) + 1] = this.textContent.trim(); });
			rows.push(r);
		});
		return {browse_columns: cols, browse_rows: rows, browse_filtered_rows: Number(N.browse.total || 0), browse_table_id: '',
			pages: Number(N.browse.pages || 1), page_number: Math.max(0, Number(N.browse.page || 1) - 1)};   // Forte: page_number from 0 (0.8.974)
	}
	// the run filter of the opening run button / nuForm(…, filter) (sob_run_filter → ?rf=) – nuBuilder Forte's breadcrumb property "filter"
	// nuFORM.getCurrent().browse_sql: the hash variables of the list's SQL that had no value (not the SQL – see pages/nuform.php)
	function browseSqlLeft() { return N.browse && N.browse.unset ? N.browse.unset.join(' ') : ''; }
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
	/** nuBuilder Forte's breadcrumb of the page (nuformclass.js addBreadcrumb + nuform.js nuSetFormProperties, 0.8.981): the same keys
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
	// nuBuilder Forte globals / helpers form code relies on (0.8.960): window.nuImages (the Files form fills it per row),
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

	// =================================================================== formatting (assets/js/nu/nuformat.js)
	var addFormatting = NuFormatLib.add, removeFormatting = NuFormatLib.remove;

	/** A stored date / date-time / time value that exists (2026-02-30 does not). */
	function validDate(v) {
		if (/^\d{2}:\d{2}(:\d{2})?$/.test(v)) return +v.substr(0, 2) < 24 && +v.substr(3, 2) < 60;
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(v);
		if (!m) return false;
		if (m[1] === '0000') return true;                                    // nuBuilder's empty date
		var d = new Date(+m[1], +m[2] - 1, +m[3]);
		return d.getFullYear() === +m[1] && d.getMonth() === +m[2] - 1 && d.getDate() === +m[3] && (m[4] === undefined || (+m[4] < 24 && +m[5] < 60));
	}
	/** Applies the display format of an input and marks negative numbers / incomplete masks. */
	function applyFormat(el, typing) {
		var f = el.getAttribute && el.getAttribute('data-nu-format');
		if (!f) return;
		if (typing && f.charAt(0).toUpperCase() === 'T') {
			var typed = NuFormatLib.mask(el.value, f.substring(2));
			if (typed.masked !== el.value && typed.raw !== '') el.value = typed.masked;
		} else if (el.value !== '') {
			var raw = removeFormatting(el.value, f), before = el.value;
			if (f.charAt(0).toUpperCase() === 'D' && !validDate(raw)) {        // nuBuilder (nuReformat): a date it cannot read is cleared (0.8.383)
				el.value = '';
				['nuFormatValueClearedGlobal', 'nuFormatValueCleared'].forEach(function (fn) {
					if (typeof window[fn] === 'function') { try { window[fn]($(el), before); } catch (x) { reportError(x.message, fn); } }
				});
				el.classList.remove('nuNegative');
				return;
			}
			var formatted = addFormatting(raw, f);
			if (formatted !== el.value) el.value = formatted;
			el.classList.toggle('nuNegative', NuFormatLib.cssClass(raw, f) !== '');
		}
		if (el.value === '') el.classList.remove('nuNegative');
		if (f.charAt(0).toUpperCase() === 'T') el.classList.toggle('is-invalid', !typing && !NuFormatLib.maskComplete(el.value, f));
	}
	N.applyFormat = applyFormat;

	// text masks: format while typing (only when the caret is at the end and not while deleting)
	$(document).on('input', '#nuEditForm input[data-nu-format^="T|"]', function (e) {
		var oe = e.originalEvent || {};
		if (/^delete/.test(oe.inputType || '') || this.selectionStart !== this.value.length) return;
		applyFormat(this, true);
	});

	window.nuFORM = window.nuFORM || {};
	nuFORM.addFormatting = addFormatting;
	nuFORM.removeFormatting = function (v, f, id) { return rawFormatted(v, f, id); };
	nuFORM.getCurrent = function () {
		// + browse_columns / browse_rows / … of a list, as Forte's breadcrumb (form code: nuFORM.getCurrent().browse_columns)
		return $.extend(nuCurrentProperties(), {hash: nuHashFromEditForm()});
	};
	nuFORM.SQLFields = function (sql) {
		var m = /select\s+([\s\S]*?)\s+from\s/i.exec(String(sql || ''));
		if (!m) return [];
		return m[1].split(',').map(function (s) { s = s.trim(); var a = /\s+as\s+(\w+)$/i.exec(s); return a ? a[1] : s.replace(/^.*\./, ''); });
	};

	// nuBuilder's nuFORM (nuFormObject) – thin wrappers onto nuBuilder Next's functions (0.8.383). The table / form schema is not
	// sent to the browser: tableSchema / formSchema stay empty, getTables() and the field lists return [].
	// nuBuilder Forte keeps the breadcrumb's state in nuFORM (nuformclass.js addBreadcrumb) – form code reads e.g.
	// nuFORM.getProperty('tab_start')[0].tabNumber; those keys come from the page (0.8.957), the rest are properties
	var crumbSet = {};
	nuFORM.getProperty = function (f) {
		if (Object.prototype.hasOwnProperty.call(crumbSet, f)) return crumbSet[f];
		var b = crumbBase();
		if (Object.prototype.hasOwnProperty.call(b, f)) return b[f];
		return nuGetProperty(f);
	};
	nuFORM.setProperty = function (f, v) { if (Object.prototype.hasOwnProperty.call(crumbBase(), f)) { crumbSet[f] = v; return; } return nuSetProperty(f, v); };
	nuFORM.subforms = function () { return [''].concat($('#nuEditForm .nuSubform[id]').map(function () { return this.id; }).get().filter(function (id) { return (N.subforms || {})[id]; })); };
	nuFORM.subform = function (id) {
		if (id) return nuSubformObject(id);
		// '' = the main form as a one-row sub-form object, as nuBuilder Forte: ID, nuDelete, then the fields; checkboxes as 0 / 1 (0.8.984)
		var v = mainValues(), keys = Object.keys(v);
		return {id: '', foreign_key: '', primary_key: N.form.primary_key || '', object_id: '', table: N.form.table || '',
			fields: ['ID', 'nuDelete'].concat(keys), rows: [[N.record_id, 0].concat(keys.map(function (k) { var el = g(k); return el && el.type === 'checkbox' ? (el.checked ? 1 : 0) : v[k]; }))],
			deleted: [0], edited: [[0, 0].concat(keys.map(function (k) { var el = g(k); return el && el.classList.contains('nuEdited') ? 1 : 0; }))], columns: [],
			chartData: null, chartDataPivot: null};
	};
	// nuBuilder Forte's nuFORM.data(): the main form as id 'nuBuilder4EditForm' (object_id = the form, action 'save'), then its sub-forms
	nuFORM.data = function () {
		return nuFORM.subforms().map(function (id) {
			var o = nuFORM.subform(id); o.columns = null; o.action = 'save';
			if (id === '') { o.id = 'nuBuilder4EditForm'; o.object_id = N.form.id; }
			return o;
		});
	};
	nuFORM.calc = function (field) {
		var p = String(field).split('.');
		if (p.length !== 2) { var el = g(field); return el ? nuFORM.removeFormatting(el.value, el.getAttribute('data-nu-format') || '') : 0; }
		var o = nuSubformObject(p[0]), c = o.fields.indexOf(p[1]), v = 0;
		if (c < 0) return 0;
		o.rows.forEach(function (r, i) { if (!o.deleted[i]) v = parseFloat((Number(v) + Number(r[c])).toPrecision(10)); });
		return Number(v);
	};
	nuFORM.scrollList = function (e, l) {
		if (!e || !Array.isArray(l) || !l.length || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
		var el = e.target, i = l.indexOf(el.value);
		i = i < 0 ? (e.key === 'ArrowDown' ? 0 : l.length - 1) : (i + (e.key === 'ArrowDown' ? 1 : l.length - 1)) % l.length;
		$(el).val(l[i]).trigger('change');
		if (e.preventDefault) e.preventDefault();
	};
	nuFORM.tableSchema = nuFORM.tableSchema || {};
	nuFORM.formSchema = nuFORM.formSchema || {};
	nuFORM.scroll = nuFORM.scroll || [];
	nuFORM.getTables = nuFORM.getJustTables = function () { return Object.keys(nuFORM.tableSchema); };
	nuFORM.getForms = function () { return Object.keys(nuFORM.formSchema); };
	nuFORM.tableFields = nuFORM.formFields = function () { return []; };
	nuFORM.dataType = function () { return ''; };
	nuFORM.tablesFromSQL = function (sql) {
		var out = [], re = /\b(?:from|join)\s+[`"[]?([\w.]+)/gi, m;
		while ((m = re.exec(String(sql || ''))) !== null) if (out.indexOf(m[1]) < 0) out.push(m[1]);
		return out;
	};
	Object.defineProperty(nuFORM, 'edited', {configurable: true, get: function () { return !!N.edited; }, set: function (v) { N.edited = !!v; }});
	Object.defineProperty(nuFORM, 'breadcrumbs', {configurable: true, get: function () {   // earlier forms (title / url), the current one = its properties
		return (N.breadcrumbs || []).slice(0, -1).map(function (b) { return {title: b.title || '', url: b.url || ''}; }).concat([nuCurrentProperties()]);
	}});
	nuFORM.removeLast = nuFORM.removeAfter = nuFORM.addBreadcrumb = function () { console.warn('nuFORM: breadcrumbs are kept by nuBuilder Next.'); };

	// nuBuilder's nuSERVERRESPONSE (its last answer: the form's properties) – built from nuCurrentProperties() when read (0.8.383)
	var serverResponse = null;
	Object.defineProperty(window, 'nuSERVERRESPONSE', {configurable: true,
		get: function () {
			if (serverResponse) return serverResponse;
			return $.extend({viewSchema: [], tableSchema: {}, formSchema: {}, buttons: {}, user_a11y: false}, nuCurrentProperties(), {
				title: N.form.description || '', search: N.browse ? N.browse.q : '', browse_filtered_rows: N.browse ? N.browse.total : 0,
				home_id: (N.user && N.user.home_id) || '',
				objects: Object.keys(N.objects || {}).map(function (id) { return $.extend({id: id}, N.objects[id]); })
			});
		},
		set: function (v) { serverResponse = v; }
	});

	// images of form code with a path relative to nuBuilder Forte's folder (img src="custom/graphics/x.png") that are not in nuBuilder
	// Next's folder: loaded once more from Forte's folder (0.8.965)
	document.addEventListener('error', function (e) {
		var el = e.target;
		if (!el || el.tagName !== 'IMG' || !N.forte_base || el.getAttribute('data-nu-forte')) return;
		var src = el.getAttribute('src') || '';
		if (src === '' || /^([a-z][a-z0-9+.-]*:|\/|#|\.\.\/)/i.test(src)) return;
		el.setAttribute('data-nu-forte', '1');
		el.setAttribute('src', N.forte_base + src);
	}, true);

	// =================================================================== values
	function rawValue(el) {
		if (!el) return '';
		if (el.type === 'checkbox') return el.checked ? '1' : '0';
		if (el.tagName === 'SELECT' && el.multiple) return $(el).val() || [];
		// a chosen option that is disabled has no value (jQuery's val(), so nuBuilder Forte's): nuSelectAddEnglishOption adds such a
		// placeholder (value 1) to an empty language select – it was saved as the language (0.8.984)
		// (only the option or its <optgroup> – a disabled select (nuDisable) keeps its value, 0.8.989)
		if (el.tagName === 'SELECT') { var so = el.options[el.selectedIndex]; return !so || so.disabled || (so.parentNode && so.parentNode.tagName === 'OPTGROUP' && so.parentNode.disabled) ? '' : so.value; }
		var f = el.getAttribute('data-nu-format');
		return f ? rawFormatted(el.value, f, el.id) : el.value;
	}
	N.rawValue = rawValue;

	/** Number without its format; nuBuilder's nuOnRawValueFormatted(id, rawValue, format) may change it (0.8.345). */
	function rawFormatted(v, f, id) {
		var r = removeFormatting(v, f);
		if (String(f || '').charAt(0).toUpperCase() === 'N' && typeof window.nuOnRawValueFormatted === 'function') {
			var m = callHook('nuOnRawValueFormatted', id || '', r, f);
			if (m !== undefined) r = m;
		}
		return r;
	}

	function isBtn(el) { return el.tagName === 'BUTTON' || (el.tagName === 'INPUT' && el.type === 'button'); }
	function btnLabel(el) { return el.querySelector ? el.querySelector('.nuButtonLabel') : null; }
	api('nuGetValue', function (id, method) {
		var el = g(id);
		if (!el) return null;
		if (el.type === 'checkbox' || el.type === 'radio') return el.checked;
		// nuBuilder Forte: a button gives its label, a label its text (method for / html), a WYSIWYG field the editor's content
		if (!method && isBtn(el)) return el.tagName === 'INPUT' ? el.value : (btnLabel(el) || el).textContent;
		if (el.tagName === 'LABEL') return method === 'for' ? (el.getAttribute('for') || null) : (method === 'html' ? el.innerHTML : el.textContent);
		if (!method && window.nuGetTinyMCEEditor && nuGetTinyMCEEditor(id)) return nuTinyMCEGetContent(id);
		if (el.tagName === 'SELECT' && !el.multiple && (method === undefined || method === 'val')) return el.value === undefined || el.selectedIndex < 0 ? '' : el.value;
		if (method === 'text' && el.tagName === 'SELECT') return $(el).find('option:selected').text();
		if (method === 'code') return (g(id + 'code') || {}).value;
		if (method === 'description') return (g(id + 'description') || {}).value;
		if (method === 'html') return el.innerHTML;
		if (el.tagName === 'SELECT' && el.multiple) return $(el).val();
		if ('value' in el && el.tagName !== 'DIV') return el.value;
		return el.textContent;
	});

	// change: false = no change event and, as in nuBuilder Forte, the field is not marked as edited (0.8.951)
	api('nuSetValue', function (id, value, method, change) {
		var el = g(id);
		if (!el) return false;
		change = change !== false;
		// a lookup: the id is looked up and code / description follow (nuBuilder Forte: nuGetLookupId)
		if (el.getAttribute('data-nu-type') === 'lookup' && g(id + 'code')) { nuGetLookupId(value, id); return true; }
		if (method === undefined && isBtn(el)) {
			if (el.tagName === 'INPUT') el.value = value; else { var lb = btnLabel(el); (lb || el).textContent = value; if (!lb && el.classList.contains('nuActionButton')) el.value = value; }
			return true;
		}
		if (el.tagName === 'LABEL') {
			if (method === 'for') el.setAttribute('for', value); else if (method === 'text') el.textContent = value; else nuSetLabelText(id, value, change);
			return true;
		}
		if (method !== 'html' && method !== 'text' && window.nuGetTinyMCEEditor && nuGetTinyMCEEditor(id)) {
			nuTinyMCESetContent(id, value); el.value = value;
			if (change) markEdited(el);
			return true;
		}
		if (method === 'html' || (!('value' in el) || el.tagName === 'DIV')) { if (method === 'text') el.textContent = value; else el.innerHTML = value; return true; }
		if (el.type === 'checkbox' || el.type === 'radio') el.checked = value === true || value === 1 || value === '1' || value === 'true';
		else if (el.tagName === 'SELECT' && (value === null || value === undefined || (value === '' && !$(el).find('option[value=""]').length))) el.selectedIndex = -1;
		else if (method === 'text' && el.tagName === 'SELECT') $(el).find('option').filter(function () { return $(this).text().replace(/\u00a0/g, ' ') === value; }).first().prop('selected', true);
		else $(el).val(value);
		if (el.tomselect) el.tomselect.sync();
		if (change) { $(el).addClass('nuEdited'); $(el).trigger('change'); }
		return true;
	});

	api('nuGetText', function (id) { return nuGetValue(id, 'text'); });
	api('nuSetText', function (id, v) { return nuSetValue(id, v, 'text'); });
	// placeholder: without text the label is used; translated unless translate === false
	api('nuSetPlaceholder', function (id, t, translate) {
		// without text: the field's format (dd.mm.yyyy, 1000.00 – Forte's data-nu-format minus "D|" / "N|"), else its label (0.8.975)
		if (t === undefined || t === null || t === '') {
			var fmt = String($('#' + id).attr('data-nu-format') || '');
			t = fmt.length > 2 ? fmt.substring(2) : labelText(id);
		}
		$('#' + id + ($('#' + id).attr('data-nu-type') === 'lookup' ? 'code' : '')).attr('placeholder', translate === false ? t : nuTranslate(t));
	});
	// keeps the validation markers (* / key) after the new text
	api('nuSetLabelText', function (id, t, translate) {
		if (/^label_/.test(id) && g(id) && g(id).tagName === 'LABEL') id = id.slice(6);   // the label's own id, as nuBuilder Forte
		var l = $('#label_' + id), marks = l.children('.nuMarks').detach();
		l.empty().append(safeFrag(translate ? nuTranslate(t) : t)).append(marks);
	});
	// nuBuilder Forte: the title shows while the mouse is over the field (translated); labelHover = also over its label;
	// an options object = nuSetAdvancedToolTip
	api('nuSetToolTip', function (id, t, labelHover) {
		if (t && typeof t === 'object') { nuSetAdvancedToolTip(id, t); return; }
		var sel = '#' + id + (labelHover ? ', #label_' + id : '');
		$(sel).off('.nuTip').on('mouseenter.nuTip', function () { this.setAttribute('title', nuTranslate(t)); })
			.on('mouseleave.nuTip', function () { this.removeAttribute('title'); });
	});

	/** Values of the main form (unformatted), for save and server calls. */
	function mainValues() {
		var v = {};
		$('#nuEditForm [data-nu-field]').each(function () {
			if (this.getAttribute('data-nu-prefix') || this.classList.contains('nuLookupCode') || this.classList.contains('nuLookupDescription')) return;
			// form controls only – sub-form column titles (th) carry data-nu-field too (0.8.984: they came as null fields)
			if (this.type === 'button' || ['INPUT', 'SELECT', 'TEXTAREA'].indexOf(this.tagName) < 0) return;
			v[this.getAttribute('data-nu-field')] = rawValue(this);
		});
		return v;
	}
	N.mainValues = mainValues;

	api('nuHashFromEditForm', function () {
		var v = mainValues(), out = [];
		Object.keys(v).forEach(function (k) { out.push([k, v[k]]); });
		return out;
	});

	// =================================================================== visibility / enabling (nuBuilder nucommon.js, adapted)
	// nuBuilder Next renders every object in a wrapper #nuw_<id> (label + control; subform cells too), tabs are Bootstrap panes.
	// So hiding is done on the wrapper with the class nuHidden: it stays hidden when tabs change, and nuBuilder's
	// data-nu-tab="x…" marker is not needed. Objects without a wrapper (subform table cells) hide their parts.

	/** nuBuilder: ids of the parts of an object ([id, label_id, …]) and its type ('lookup', 'select2' or ''). */
	api('nuObjectComponents', function (id) {
		var componentIds = [id, 'label_' + id], type = '';
		var el = g(id);
		if (el && el.getAttribute('data-nu-type') === 'lookup') {
			componentIds.push(id + 'code', id + 'button', id + 'description');
			type = 'lookup';
		} else if (el && el.tomselect) {
			type = 'select2';                                   // Tom Select (nuBuilder: Select2)
		}
		return {componentIds: componentIds, type: type};
	});

	/** Control elements of an object (no label): the field, lookup code / button / description, Tom Select. */
	function controls(id) {
		var c = nuObjectComponents(id), list = [];
		c.componentIds.forEach(function (cid, i) { if (i !== 1) { var e = g(cid); if (e) list.push(e); } });
		return list;
	}
	function wrapOf(id) { return g('nuw_' + id); }

	function hideOne(id) {
		var w = wrapOf(id);
		if (w) { w.classList.add('nuHidden'); return; }
		nuObjectComponents(id).componentIds.forEach(function (cid) { var e = g(cid); if (e) e.classList.add('nuHidden'); });
	}
	function showOne(id) {
		var w = wrapOf(id), shown = false;
		var parts = w ? [w] : nuObjectComponents(id).componentIds.map(g).filter(Boolean);
		parts.forEach(function (e) {
			if (e.classList.contains('nuHidden') || e.style.display === 'none') shown = true;
			e.classList.remove('nuHidden');
			if (e.style.display === 'none') e.style.display = '';     // hidden by nuBuilder access / old style code
		});
		if (w) nuObjectComponents(id).componentIds.forEach(function (cid) {   // parts hidden one by one with $().hide()
			var e = g(cid);
			if (e && e.style.display === 'none') { e.style.display = ''; shown = true; }
		});
		return shown;
	}

	api('nuHide', function (ids) { arr(ids).forEach(hideOne); });
	// openTab: accepted for nuBuilder compatibility – Bootstrap tabs need no re-layout after showing objects
	api('nuShow', function (ids, visible, openTab) {
		arr(ids).forEach(function (id) { if (visible === false) hideOne(id); else showOne(id); });
	});
	api('nuRemove', function (ids) {
		arr(ids).forEach(function (id) {
			var el = g(id);
			if (el && el.tomselect) el.tomselect.destroy();
			var w = wrapOf(id);
			if (w) w.remove();
			nuObjectComponents(id).componentIds.forEach(function (cid) { var e = g(cid); if (e) e.remove(); });
		});
	});

	api('nuIsVisible', function (id) {
		if (!id) return undefined;
		var $e = typeof id === 'string' ? $('#' + id) : $(id);
		if (!$e.length) return null;
		return $e.css('display') !== 'none' && $e.css('visibility') !== 'hidden' && !$e.is(':hidden');
	});
	api('nuIsHidden', function (id) { return !nuIsVisible(id); });

	/** nuBuilder's optional hook nuOnDisableGlobal(id, $component): return false = keep the component usable. */
	function allowDisable(id, el) {
		if (typeof window.nuOnDisableGlobal !== 'function') return true;
		try { return window.nuOnDisableGlobal(id, $(el)) !== false; } catch (x) { reportError(x.message, 'nuOnDisableGlobal'); return true; }
	}
	function setState(el, disabled, readonly) {
		el.disabled = disabled;
		if ('readOnly' in el) el.readOnly = readonly;
		el.classList.toggle('nuReadonly', disabled || readonly);
		if (el.tomselect) el.tomselect[disabled || readonly ? 'disable' : 'enable']();
		var picker = g(el.id + '_file');                          // file object: hidden picker + remove button
		if (picker) { picker.disabled = disabled || readonly; $(picker).closest('.nuFileField').find('.nuFileRemove').prop('disabled', disabled || readonly); }
		if (el.classList.contains('nuDate')) el.onclick = disabled || readonly ? null : function () { nuPopupCalendar(this); };
		if (el.nuInlineCM) el.nuInlineCM.setOption('readOnly', disabled || readonly);          // inline code editor (code snippets)
	}
	/** Kinds of fields that have no readonly state: they are disabled instead (the value is still saved – nuBuilder Next reads it itself). */
	function noReadonly(el) {
		return el.tagName === 'SELECT' || el.tagName === 'BUTTON' || ['checkbox', 'radio', 'button', 'file', 'color', 'range'].indexOf(el.type) !== -1;
	}

	/**
	 * nuButtonLoading(button, spinning = true, autoEnableAfterSeconds = 0) – as nuBuilder: a spinner on the button and the button
	 * disabled while a background process runs; nuButtonLoading(button, false) ends it, autoEnableAfterSeconds ends it by itself.
	 * button: id, element or jQuery. <button> (Run buttons, own HTML): Bootstrap spinner in place of the icon, before .nuButtonLabel;
	 * <input type="button"> (input objects of type button): animated spinner in the button (nuform.css .nuButtonSpinning).
	 * Also: keeps the width (no jump), aria-busy for screen readers, a button that was disabled before stays disabled, calling it
	 * again while spinning only restarts the auto-enable time, a removed button is ignored. Returns the button (or null).
	 */
	api('nuButtonLoading', function (button, spinning, autoEnableAfterSeconds) {
		var b = typeof button === 'string' ? g(button) : (button && button.jquery ? button[0] : button);
		if (!b || !b.nodeType) return null;
		spinning = spinning !== false;
		clearTimeout(b._nuSpinTimer); b._nuSpinTimer = null;
		var on = b.hasAttribute('data-nu-spinning');
		if (spinning) {
			if (!on) {
				if (b.disabled || b.classList.contains('nuReadonly')) return null;   // as nuBuilder: a disabled button does not start
				b.setAttribute('data-nu-spinning', '1');
				b._nuSpinMinWidth = b.style.minWidth;
				b.style.minWidth = Math.ceil(b.getBoundingClientRect().width) + 'px';
				b.disabled = true;
				b.classList.add('disabled', 'nuButtonSpinning');
				b.setAttribute('aria-busy', 'true');
				if (b.tagName !== 'INPUT') {
					var icon = b.querySelector('i:not([nu-data-spinner]), svg:not([nu-data-spinner])');
					if (icon) { icon.classList.add('d-none'); icon.setAttribute('data-nu-spinner-hidden', '1'); }
					var sp = document.createElement('span');
					sp.className = 'spinner-border spinner-border-sm nuButtonSpinner';
					sp.setAttribute('nu-data-spinner', 'true');
					sp.setAttribute('aria-hidden', 'true');
					var label = b.querySelector('.nuButtonLabel');
					if (label) { sp.classList.add('me-1'); label.parentNode.insertBefore(sp, label); }
					else if (icon) icon.parentNode.insertBefore(sp, icon);
					else if (b.textContent.trim() !== '') { sp.classList.add('me-1'); b.insertBefore(sp, b.firstChild); }
					else b.appendChild(sp);
				}
			}
			var secs = Number(autoEnableAfterSeconds) || 0;
			if (secs > 0) b._nuSpinTimer = setTimeout(function () { if (b.isConnected) nuButtonLoading(b, false); }, secs * 1000);
		} else if (on) {
			b.removeAttribute('data-nu-spinning');
			b.disabled = false;
			b.classList.remove('disabled', 'nuButtonSpinning');
			b.removeAttribute('aria-busy');
			b.style.minWidth = b._nuSpinMinWidth || '';
			b.querySelectorAll('[nu-data-spinner]').forEach(function (x) { x.remove(); });
			b.querySelectorAll('[data-nu-spinner-hidden]').forEach(function (x) { x.classList.remove('d-none'); x.removeAttribute('data-nu-spinner-hidden'); });
		}
		return b;
	});
	$.fn.nuButtonLoading = function (spinning, autoEnableAfterSeconds) { return this.each(function () { nuButtonLoading(this, spinning, autoEnableAfterSeconds); }); };

	api('nuEnable', function (ids, enable) {
		if (enable === false) { nuDisable(ids); return; }
		arr(ids).forEach(function (id) { controls(id).forEach(function (el) { setState(el, false, false); }); });
	});
	api('nuDisable', function (ids) {
		arr(ids).forEach(function (id) {
			controls(id).forEach(function (el) {
				el.classList.add('nuReadonly');
				if (allowDisable(id, el)) setState(el, true, true);
			});
		});
	});
	// read only: text can still be selected and copied; selects, checkboxes and buttons cannot be read only and are disabled
	api('nuReadonly', function (ids, readonly) {
		var on = readonly !== false;
		arr(ids).forEach(function (id) {
			controls(id).forEach(function (el) {
				if (id + 'description' === el.id) return;           // lookup description is always read only
				if (noReadonly(el)) setState(el, on, on); else setState(el, false, on);
			});
		});
	});
	// also read only by the access settings of the object (rendered readonly / disabled by the server)
	api('nuIsDisabled', function (id) { var e = g(id); return !!e && (e.disabled || e.readOnly === true || e.classList.contains('nuReadonly')); });
	api('nuIsEnabled', function (id) { var e = g(id); return !!e && !nuIsDisabled(id); });

	/** Focus an object (lookup: its code field), on its tab. */
	api('nuSetFocus', function (id) {
		var el = g(id);
		if (el && el.getAttribute('data-nu-type') === 'lookup') el = g(id + 'code') || el;
		if (!el) return false;
		var pane = el.closest('.nuTabPane');
		if (pane && !pane.classList.contains('active')) nuSelectTab(g(pane.getAttribute('aria-labelledby')));
		setTimeout(function () { if (el.tomselect) el.tomselect.focus(); else el.focus(); }, pane ? 160 : 0);
		return true;
	});

	/** Removes the empty option of a select; setIndex: index to select afterwards (number), or keep the selection. */
	// nuBuilder Forte: no id = every select of the page; setIndex sets the selected index without a change event
	api('nuSelectRemoveEmpty', function (ids, setIndex) {
		var els = ids === undefined || ids === null ? Array.prototype.slice.call(document.querySelectorAll('select')) : arr(ids).map(g);
		els.forEach(function (el) {
			if (!el || el.tagName !== 'SELECT') return;
			$(el).find('option').filter(function () { return String(this.value).trim() === '' && $(this).text().trim() === ''; }).remove();
			if (setIndex !== undefined && setIndex !== null && setIndex !== false) el.selectedIndex = Number(setIndex);
			if (el.tomselect) { el.tomselect.clearOptions(); el.tomselect.sync(); }
		});
	});

	// =================================================================== messages
	// nuBuilder Forte: nuMessage(lines) · (lines, timeout[, callback]) · (title, lines[, timeout[, callback]]);
	// a first line <h1>…<h5> becomes the title; after timeout ms the message goes and callback runs (0.8.951)
	var HEAD = /<h[1-5]>(.*?)<\/h[1-5]>/i;
	api('nuMessage', function () {
		var a = Array.prototype.slice.call(arguments).filter(function (x) { return x !== undefined; }), title = '', lines, timeout = null, callback = null;
		if (a.length === 1 && typeof a[0] === 'string' && HEAD.test(a[0])) { var mh = a[0].match(HEAD); a = [mh[1], a[0].replace(HEAD, '')]; }
		if (a.length >= 2 && Number.isInteger(a[1])) { lines = arr(a[0]); timeout = a[1]; callback = typeof a[2] === 'function' ? a[2] : null; }
		else if (a.length === 1 && Array.isArray(a[0])) lines = a[0];
		else if (a.length >= 2 && !Array.isArray(a[0])) {
			title = a[0];
			var rest = a.slice(1);
			if (rest.length && typeof rest[rest.length - 1] === 'function') callback = rest.pop();
			if (rest.length > 1 && Number.isInteger(rest[rest.length - 1])) timeout = rest.pop();
			lines = rest.reduce(function (x, y) { return x.concat(arr(y)); }, []);
		}
		else lines = a.reduce(function (x, y) { return x.concat(arr(y)); }, []);
		if (!title && lines.length && HEAD.test(String(lines[0]))) { var m0 = String(lines[0]).match(HEAD); title = m0[1]; lines = lines.slice(1); if (String(lines[0] || '').trim() === '' && lines.length) lines = lines.slice(1); }
		if (designQuiet('message', (title ? title + ': ' : '') + lines.join(' · '))) return $('<div></div>');
		$('#nuMessageDiv').remove();
		var box = $('<div id="nuMessageDiv" class="nuMessage shadow" role="alertdialog" aria-live="assertive"></div>');
		var close = $('<button type="button" class="btn-close nuMessageClose" aria-label="Close"></button>').on('click', function () { box.remove(); });
		box.append(close);
		var put = function (div, h) { return safeMessages ? div.append(safeFrag(h)) : div.html(h); };
		if (title) box.append(put($('<div class="nuMessageTitle fw-semibold mb-2"></div>'), title));
		lines.forEach(function (l) { box.append(put($('<div class="nuMessageLine"></div>'), String(l))); });
		$('body').append(box);
		close.trigger('focus');
		if (timeout) setTimeout(function () { box.fadeOut('slow', function () { box.remove(); }); if (callback) callback(); }, timeout);
		return box;
	});
	api('nuMessageRemove', function () { $('#nuMessageDiv').remove(); });
	$(document).on('keydown', function (e) { if (e.key === 'Escape') $('#nuMessageDiv').remove(); });

	api('nuDisplayError', function (data) {
		var errs = (data && data.errors) || [];
		if (!errs.length) return false;
		var box = nuMessage(errs.map(function (e) { return esc(e.message || e); }));
		callHook('nuOnMessage', box, errs);                                  // nuBuilder: (messageDiv, errors)
		errs.forEach(function (e) { if (e.field) $('#' + e.field + ', #' + e.field + 'code').addClass('is-invalid'); });
		return true;
	});

	// =================================================================== properties
	// Form properties travel with the forms opened from here (kept on the server, the URL carries only a key: N.ps).
	// persist = true: global hash cookie until logout (also visible in nuBuilder with shared login).
	api('nuSetProperty', function (k, v, persist) {
		k = String(k);
		if (N.locked.indexOf(k) >= 0 && N.props[k] !== v) console.warn('nuSetProperty: "' + k + '" was set in PHP – the server keeps its value.');
		N.props[k] = v;
		propsDirty = true;
		if (persist === true) {
			N.globals[k] = v;
			return call('global', {name: k, value: v}).then(function (res) { if (res && res.globals) N.globals = res.globals; else nuDisplayError(res); return res; });
		}
	});
	api('nuGetProperty', function (k) {
		if (k in N.props) return N.props[k];
		if (k in N.globals) return N.globals[k];
		var c = nuCurrentProperties();
		return k in c ? c[k] : undefined;
	});
	api('nuDeleteProperty', function (k) {
		delete N.props[k];
		propsDirty = true;
		if (k in N.globals) {
			delete N.globals[k];
			return call('global', {name: k, 'delete': true});
		}
	});
	api('nuGetGlobalProperties', function () { return $.extend({}, N.globals); });

	/** Runs fn(scopeKey) with a server scope that holds the current properties (stored first when they changed here). */
	// 0.8.976: from an edit form the opened form also gets this record's fields as hash variables (below its own values), as nuBuilder
	// Forte's nuHashFromEditForm; from a list nothing – a scope that carries an older caller is replaced, so it is not passed on
	function withScope(fn) {
		var edit = N.mode === 'edit' && !!N.form;
		if (!propsDirty && !edit && !N.caller) { fn(N.ps || ''); return; }
		var data = {};
		if (edit) { try { data.caller = N.mainValues ? N.mainValues() : {}; } catch (x) { data.caller = {}; } }
		call('props', data).then(function (res) {
			if (res && res.ps) {
				// the new scope while fn builds its URL (formUrl reads N.ps); this page keeps its own unless properties changed
				var keep = N.ps, dirty = propsDirty;
				N.ps = res.ps; propsDirty = false;
				try { fn(res.ps); } finally { if (!dirty) N.ps = keep; }
				return;
			}
			fn(N.ps || '');
		});
	}
	N.withScope = withScope;

	/** Opens a URL built with the current scope; target 'blank' opens the tab at once (pop-up blockers), a function gets the URL. */
	function go(build, target) {
		// 'blank': the tab is opened right after the click, not during it – during a Ctrl+click the browser would put it in the
		// background (it reads the Ctrl key of the running event); a moment later the click still allows the pop-up (0.8.155)
		if (target === 'blank') {
			setTimeout(function () {
				var tab = window.open('', '_blank');
				if (!tab) { go(build, null); return; }        // pop-up blocked: open it here
				withScope(function () { tab.location.href = build(); });
			}, 0);
			return;
		}
		var w = null;
		withScope(function () {
			var url = build();
			if (typeof target === 'function') target(url);
			else if (w) w.location.href = url;
			else window.location.href = url;
		});
	}

	// =================================================================== server calls
	function call(action, data) {
		data = $.extend({action: action, form_id: N.form.id, record_id: N.record_id, props: N.props, ps: N.ps || ''}, data || {});
		return fetch(N.base + '?p=formapi', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-CSRF-Token': N.csrf, 'Accept': 'application/json'},
			body: JSON.stringify(data)
		}).then(function (r) {
			// a procedure that sends a file (header('Content-Disposition: attachment …'), NuXlsx::download, PhpSpreadsheet …) – 0.8.756:
			// nuBuilder Forte runs it in its own window, here the answer is the file itself, saved by nuRunPHP / nuRunPHPHidden
			var cd = r.headers.get('Content-Disposition') || '';
			if (r.ok && /attachment/i.test(cd)) {
				var m = /filename\*=UTF-8''([^;]+)/i.exec(cd), m2 = /filename="?([^";]+)"?/i.exec(cd);
				var name = m ? decodeURIComponent(m[1]) : (m2 ? m2[1] : 'download');
				return r.blob().then(function (b) { return {ok: true, file: {blob: b, name: name}}; });
			}
			return r.json().catch(function () { return {ok: false, errors: [{message: 'HTTP ' + r.status}]}; });
		})
			.then(function (res) {
				// draft preview (0.8.386): Save / Delete write live data – asked once per session, then sent again
				if (res && res.code === 'preview_confirm' && !data.preview_confirm) {
					if (!window.confirm((res.errors && res.errors[0] && res.errors[0].message) || '')) return {ok: false, errors: []};
					return call(action, $.extend({}, data, {preview_confirm: true}));
				}
				return res;
			});
	}
	N.call = call;

	/** Applies props, form values (in a subform row context when prefix is given) and JS returned by the server. */
	function applyResult(res, prefix) {
		if (!res) return;
		if (res.props) Object.keys(res.props).forEach(function (k) { N.props[k] = res.props[k]; if (N.locked.indexOf(k) < 0) N.locked.push(k); });
		if (res.globals) Object.keys(res.globals).forEach(function (k) { N.globals[k] = res.globals[k]; });
		(res.deleted || []).forEach(function (k) { delete N.props[k]; delete N.globals[k]; });
		if (res.values) Object.keys(res.values).forEach(function (k) {
			var name = String(k).replace(/#ROW#/g, prefix || '');          // nuBuilder: nuSetFormValue('#ROW#field', …) in a subform row
			var id = name !== k ? name : (prefix && g(prefix + k) ? prefix + k : k);
			if (g(id)) nuSetValue(id, res.values[k]);
		});
		(res.js || []).forEach(function (js) { try { globalEval(js); } catch (e) { reportError(e.message, 'PHP nuAddJavascript'); console.error(e); } });
		nuDisplayError(res);
	}
	N.applyResult = applyResult;

	function saveFile(f) {
		var url = URL.createObjectURL(f.blob), a = document.createElement('a');
		a.href = url; a.download = f.name; a.className = 'd-none';
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
	}
	// options === true: nuBeforeSave() first, false stops it (nuBuilder Forte 4.9, 0.8.945)
	function beforeSaveOk(run) {
		if (run !== true || typeof window.nuBeforeSave !== 'function') return true;
		try { return window.nuBeforeSave() !== false; } catch (x) { reportError(x.message, 'nuBeforeSave'); return false; }
	}
	/** What nuBuilder Forte posts with a procedure (0.8.984): the breadcrumb's plain values (nuHash()['title'], ['rows'] …) and, from an
	 *  edit form, nuFORM.data() (nuHash()['nuFORMdata']). */
	function procData(code) {
		var b = crumbBase(), crumb = {};
		Object.keys(b).forEach(function (k) { var v = b[k]; crumb[k] = (typeof v === 'string' || typeof v === 'number') ? v : ''; });
		crumb.params = '';
		var d = {code: code, values: N.mode === 'edit' ? mainValues() : {}, crumb: crumb};
		if (N.mode === 'edit') { try { d.formdata = nuFORM.data(); } catch (x) { /* a sub-form without data */ } }
		return d;
	}
	api('nuRunPHPHidden', function (code, options) {
		if (!beforeSaveOk(options)) return Promise.resolve(null);
		return call('php', procData(code)).then(function (res) {
			if (res && res.file) { saveFile(res.file); return res; }
			applyResult(res); return res;
		});
	});
	api('nuRunPHPHiddenWithParams', function (code, paramName, params, runBeforeSave) {
		if (!beforeSaveOk(runBeforeSave)) return Promise.resolve(null);
		nuSetProperty(paramName, btoa(unescape(encodeURIComponent(JSON.stringify(params)))));
		return nuRunPHPHidden(code);
	});
	// nuBuilder Forte: iFrame = id of an iframe that shows the output instead of a new window; runBeforeSave = nuBeforeSave() first
	api('nuRunPHP', function (code, iFrame, runBeforeSave) {
		if (!beforeSaveOk(runBeforeSave)) return Promise.resolve(null);
		var fr = iFrame ? (parent.document.getElementById(iFrame) || g(iFrame)) : null;
		var w = fr ? null : window.open('', '_blank');
		return call('php', procData(code)).then(function (res) {
			if (res && res.file) { if (w) w.close(); saveFile(res.file); return; }       // a download: no window needed
			applyResult(res);
			if (fr) fr.srcdoc = res.output || '';
			else if (w) { w.document.open(); w.document.write(res.output || ''); w.document.close(); }
		});
	});

	api('nuAjax', function (obj, success) {
		var map = {getlookupcode: 'lookupcode', getlookupid: 'lookupid', runhiddenphp: 'php'};
		var action = map[obj.call_type] || obj.call_type;
		var values = {};
		arr(obj.hash).forEach(function (p) { if (Array.isArray(p)) values[p[0]] = p[1]; });
		return call(action, {object_id: obj.object_id, code: obj.code, pk: obj.primary_key || obj.pk, values: values}).then(function (data) {
			data.target = obj.target;
			if (success) success(data);
		});
	});

	// =================================================================== navigation
	function formUrl(f, r, extra) {
		var q = $.extend({p: 'form', f: f}, (r !== undefined && r !== null && r !== '') ? {r: r} : {}, extra || {});
		if (N.ps && !q.ps) q.ps = N.ps;
		// the opening form and record (page forms read them with $nu->parent(), 0.8.387)
		if (N.form && !q.pf) { q.pf = N.form.id; if (N.record_id && N.record_id !== '-1') q.pr = N.record_id; }
		return N.base + '?' + $.param(q);
	}
	N.formUrl = formUrl;

	/**
	 * Opens a form like nuBuilder: r '' = browse, '-1' = new record; filter = always-applied browse search words.
	 * n (target): 0 new breadcrumb (default), 1 current breadcrumb (replaces this one), 2 new tab.
	 */
	api('nuForm', function (f, r, filter, search, n) {
		n = String(n === undefined || n === null || n === '' ? '0' : n);
		var extra = {};
		if (search) extra.q = search;
		if (filter) extra.rf = filter;
		if (N.iframe) extra.iframe = '1';
		else if (n !== '2') { var bt = (N.bt || {})[n === '1' ? 'same' : 'child']; if (bt) extra.bt = bt; }
		if (n === '3') { nuPopup(f, r, filter); return; }
		go(function () { return formUrl(f, r, extra); }, n === '2' ? 'blank' : null);
	});

	/** Opens a form in a dialog (nuBuilder run target "Popup"). */
	api('nuPopup', function (f, r, filter) {
		var m = g('nuPopupModal');
		if (!m) return;
		var dlg = m.querySelector('.modal-dialog'), ttl = m.querySelector('.modal-title'), fr = m.querySelector('iframe');
		if (!m.hasAttribute('data-nu-title')) m.setAttribute('data-nu-title', ttl ? ttl.textContent : '');
		if (dlg) dlg.style.maxWidth = '';
		if (ttl) ttl.textContent = m.getAttribute('data-nu-title');
		// as nuBuilder Forte (0.8.965): the dialog shows the form's title, and a list is as wide as its columns (nuResizeBrowseColumns of
		// a form in a dialog), at most the window
		if (fr && !fr.hasAttribute('data-nu-fit')) {
			fr.setAttribute('data-nu-fit', '1');
			fr.addEventListener('load', function () {
				try {
					var w = fr.contentWindow, d = fr.contentDocument, nn = w.nuNext || {};
					if (ttl && nn.form && nn.form.description) ttl.textContent = String(nn.form.description).replace(/<[^>]*>/g, '');
					var t = d && d.getElementById('nuBrowseTable');
					if (dlg && t && !t.classList.contains('nuBrowseAutoFit')) {
						var sum = 0;
						Array.prototype.forEach.call(t.querySelectorAll('thead > tr > th'), function (th) { if (th.style.display !== 'none') sum += parseFloat(th.style.width) || th.offsetWidth; });
						dlg.style.maxWidth = Math.min(window.innerWidth - 32, Math.max(480, Math.ceil(sum) + 64)) + 'px';
					}
				} catch (x) { /* another origin */ }
			});
		}
		go(function () { return formUrl(f, r, $.extend({iframe: '1'}, filter ? {rf: filter} : {})); }, function (url) { $(m).find('iframe').attr('src', url); });
		nuBs.modal(m).show();
		callHook('nuOnPopupOpenedGlobal', f, r, filter);
	});

	/** Run objects (buttons): open a form or run a procedure, see NuRender::runInfo(). */
	// the button's own onclick (nuBuilder Forte's nuForm(…) …, written by NuRender::run – form code may have changed it): the leave
	// question first when the form is left with unsaved changes (0.8.965)
	api('nuRunButton', function (btn, e, fn) {
		if (!btn || btn.disabled || typeof fn !== 'function') return false;
		var tg = btn.getAttribute('data-nu-run-target') || '0', type = btn.getAttribute('data-nu-run-type') || 'F';
		if (type === 'F' && N.mode === 'edit' && N.edited && tg !== '2' && tg !== '3') {
			if (window.nuLeaveGuard) { window.nuLeaveGuard.ask(fn); return false; }
			if (!window.confirm(M.unsaved || 'Leave without saving?')) return false;
		}
		fn();
		return false;
	});
	$(document).on('click', '.nuRunButton', function (e) {
		if (e.isDefaultPrevented() || this.disabled) return;
		if (this.hasAttribute('data-nu-run-onclick')) return;             // runs through its own onclick (nuRunButton, 0.8.965)
		var d = this.dataset;
		if (d.nuRunType === 'P') {
			if (!d.nuRunCode) return;
			if (d.nuRunHidden === '1') nuRunPHPHidden(d.nuRunCode); else nuRunPHP(d.nuRunCode);
			return;
		}
		if (d.nuRunType === 'R') { if (d.nuRunCode) nuRunReport(d.nuRunCode); return; }
		if (d.nuRunType === 'U') {                                // a URL (0.8.830): 2 new tab (default), 3 popup window, 0 / 1 this tab
			var url = d.nuRunUrl;
			if (!url) return;
			if (d.nuRunTarget === '3') { window.open(url, '_blank', 'popup,width=1000,height=700,noopener'); return; }
			if (d.nuRunTarget !== '0' && d.nuRunTarget !== '1') { window.open(url, '_blank', 'noopener'); return; }
			var go = function () { window.location.href = url; };
			if (N.mode === 'edit' && N.edited) {
				if (window.nuLeaveGuard) { window.nuLeaveGuard.ask(go); return; }
				if (!window.confirm(M.unsaved || 'Leave without saving?')) return;
			}
			go();
			return;
		}
		if (!d.nuRunForm) return;
		var open = function () {
			if (d.nuRunTarget === '3') nuPopup(d.nuRunForm, d.nuRunRecord, d.nuRunFilter);
			else nuForm(d.nuRunForm, d.nuRunRecord, d.nuRunFilter, '', d.nuRunTarget);
		};
		// leaving the form with unsaved changes: Save / Discard / Keep editing (assets/js/nuapp.js)
		if (N.mode === 'edit' && N.edited && d.nuRunTarget !== '2' && d.nuRunTarget !== '3') {
			if (window.nuLeaveGuard) { window.nuLeaveGuard.ask(open); return; }
			if (!window.confirm(M.unsaved || 'Leave without saving?')) return;
		}
		open();
	});

	api('nuGetBreadcrumb', function (n) {
		var bc = N.breadcrumbs || [];
		if (n !== undefined && bc[n] && bc[n].url && n < bc.length - 1) {
			var to = new URL(bc[n].url, window.location.href);
			if (N.iframe) to.searchParams.set('iframe', '1');
			window.location.href = to.toString();
			return;
		}
		try {
			if (window.parent !== window && window.parent.nuNext) { $.extend(N.props, window.parent.nuNext.props); propsDirty = true; }
		} catch (e) { /* cross-origin */ }
		go(function () {
			var u = new URL(window.location.href);
			u.searchParams.set('ps', N.ps || '');
			u.searchParams.delete('saved');
			return u.toString();
		});
	});
	api('nuOpenPreviousBreadcrumb', function () {
		var bc = N.breadcrumbs || [];
		for (var i = bc.length - 2; i >= 0; i--) if (bc[i].url) { window.location.href = bc[i].url; return; }
	});
	// the browser tab's title as nuBuilder Forte (nuSetBrowserTabTitle) when a shared setting asks for it: "<prefix> - <title>" (0.8.965)
	function tabTitle(t) {
		var tt = N.tab_title;
		if (!tt) return false;
		document.title = tt.show ? (tt.prefix ? tt.prefix + ' - ' : '') + (t || 'Properties') : tt.prefix;
		return true;
	}
	api('nuSetTitle', function (t) { var f = safeFrag(t); if (!tabTitle(f.textContent.trim())) document.title = f.textContent.trim(); $('.nuBreadcrumb').last().empty().append(f); });
	$(function () { if (N.tab_title) tabTitle(($('.nuBreadcrumb').last().text() || '').trim()); });

	// =================================================================== tabs
	function tabKey() { return 'nuNextTab:' + N.form.id; }
	api('nuSelectTab', function (tab) {
		var el = typeof tab === 'number' ? g('nuTab' + tab) : (typeof tab === 'string' ? (g(tab) || $('[data-nu-tab-id="' + tab + '"].nuTab')[0]) : tab);
		if (el && nuBs.ready()) nuBs.tab(el).show();
	});
	api('nuHideTab', function (i) { $('#nuTab' + i).parent().hide(); });
	api('nuShowTab', function (i) { $('#nuTab' + i).parent().show(); });
	api('nuHideTabById', function (id) { $('.nuTab[data-nu-tab-id="' + id + '"]').parent().hide(); });
	// nuBuilder Forte: visible === false hides the tab; then the tab bar follows nuSetHideTabIfOnlyOne
	api('nuShowTabById', function (id, visible) {
		var t = $('.nuTab[data-nu-tab-id="' + id + '"]').parent();
		if (visible === false) t.hide(); else t.show();
		if (window.nuSetHideTabIfOnlyOne) nuSetHideTabIfOnlyOne();
	});

	// =================================================================== edited state
	function markEdited(el) {
		if (!el || !el.getAttribute) return;
		el.classList.add('nuEdited');
		el.classList.remove('is-invalid');
		if (!N.edited) callHook('nuOnSetSaved', false);                     // nuBuilder: nuSetSaved(false) when the record gets edited
		N.edited = true;
		$('#nuSaveButton').addClass('nuSaveEdited').prop('disabled', false);   // a change makes the record saveable again
		var row = el.closest ? el.closest('.nuSubformRow') : null;
		if (row) {
			row.classList.add('nuRowEdited');
			// typing into the blank last row adds the next blank row (nuBuilder behaviour)
			var sf = row.getAttribute('data-nu-subform');
			if (row.classList.contains('nuNewRow') && !el.classList.contains('nuSubformDelete')) {
				// the blank row's delete box is ticked, as in nuBuilder Forte – it becomes a real row now
				var nd = row.getAttribute('data-nu-prefix') ? g(row.getAttribute('data-nu-prefix') + 'nuDelete') : null;
				if (nd && nd.checked) nd.checked = false;
			}
			if (row.classList.contains('nuNewRow') && !row.nextElementSibling && !el.classList.contains('nuSubformDelete')) {
				row.classList.remove('nuNewRow');
				appendRow(sf);
			} else row.classList.remove('nuNewRow');
		}
	}

	// as nuBuilder Forte: only the record's edited state (Save button, leave question) is reset – the fields keep their nuEdited mark,
	// so values the form's code set while loading (and then called nuHasNotBeenEdited) are still saved (0.8.965)
	api('nuHasNotBeenEdited', function () {
		callHook('nuOnSetSaved', true);
		N.edited = false;
		$('#nuSaveButton').removeClass('nuSaveEdited');
	});
	api('nuSetSaved', function () { nuHasNotBeenEdited(); });

	api('nuChange', function (e) {
		var el = e && (e.target || e);
		if (!el || el.classList.contains('nuLookupCode') || el.classList.contains('nuLookupDescription')) return;
		applyFormat(el, false);
		markEdited(el);
		if (!el.classList.contains('nuCalculator')) calculate();
	});

	function calculate() {
		if (typeof window.nuCalculateForm === 'function') {
			try { window.nuCalculateForm(); } catch (x) { reportError(x.message, 'nuCalculateForm'); }
		}
	}

	$(document).on('change', '#nuEditForm [data-nu-field], #nuEditForm .nuSubformDelete', function (e) {
		// a control of form code inside an HTML / Word / Run object (a div with data-nu-field) is no field of the record:
		// nuBuilder Forte never marks the record as edited for it (a filter select of an HTML object asked "unsaved changes")
		if (e.target !== this) return;
		if (this.classList.contains('nuSubformDelete')) {
			$(this).closest('.nuSubformRow').toggleClass('nuRowDeleted', this.checked);
			markEdited(this);
			calculate();
			return;
		}
		nuChange(e);
	});
	$(document).on('input', '#nuEditForm input[data-nu-field]:not(.nuLookupCode), #nuEditForm textarea[data-nu-field]', function () { markEdited(this); });
	// object event onenter (nuBuilder: nuOnEnterKey – keydown Enter runs the code with this = the object, 0.8.462)
	$(document).on('keydown', '#nuEditForm [onenter]', function (e) {
		if (e.key !== 'Enter' || this.closest('template')) return;
		runSnippet(this.getAttribute('onenter'), e.originalEvent || e, this);
	});

	// =================================================================== calculations
	api('nuTotal', function (path) {
		path = String(path);
		var dot = path.indexOf('.');
		var toNum = function (el) {
			if (!el) return 0;
			var f = el.getAttribute('data-nu-format');
			var v = f ? removeFormatting(el.value, f) : el.value;
			var n = parseFloat(String(v).replace(',', '.'));
			return isNaN(n) ? 0 : n;
		};
		if (dot < 0) return toNum(g(path));
		var sf = path.substring(0, dot), field = path.substring(dot + 1), sum = 0;
		$('#' + sf + ' .nuSubformRow').each(function () {
			var p = this.getAttribute('data-nu-prefix');
			var del = g(p + 'nuDelete');
			if (del && del.checked) return;
			sum += toNum(g(p + field));
		});
		return Math.round(sum * 1e6) / 1e6;
	});

	api('nuCalculateForm', function (setAsEdited) {
		if (window.nuEnableCalculation === false) return;
		var f = $('[data-nu-formula]').toArray().sort(function (a, b) {
			var A = a.closest('.nuSubform') ? 0 : 1000, B = b.closest('.nuSubform') ? 0 : 1000;
			return (parseInt(a.getAttribute('data-nu-calc-order'), 10) + A) - (parseInt(b.getAttribute('data-nu-calc-order'), 10) + B);
		});
		f.forEach(function (el) {
			var formula = el.getAttribute('data-nu-formula');
			if (!formula) return;
			var v = 0;
			try { v = new Function('return (' + formula + ');')(); } catch (x) { reportError(x.message, 'calc ' + el.id); }   // eslint-disable-line no-new-func
			var fixed = addFormatting(v, el.getAttribute('data-nu-format'));
			// the blank new row of a sub-form stays blank: its calc value does not make it an edited row (nuBuilder 4.9)
			var blankRow = el.closest('.nuNewRow');
			if (el.value !== fixed) { el.value = fixed; if (setAsEdited !== false && !blankRow) markEdited(el); }
			el.classList.toggle('nuNegative', NuFormatLib.cssClass(v, el.getAttribute('data-nu-format')) !== '');
			if (window.nuCalculated) { try { window.nuCalculated(el, v, fixed); } catch (x) { reportError(x.message, 'nuCalculated'); } }
		});
	});

	// =================================================================== scripts of HTML objects (0.8.383)
	// The server gives them type="text/nu-deferred" (NuRender::deferScripts): they run here, in page order, once jQuery and the
	// runtime exist – as in nuBuilder, which inserts HTML objects after loading its libraries. A script with src is loaded first;
	// the scripts after it wait for it.
	/** nuBuilder Forte runs form code after the page has loaded, so a handler that waits for the page (DOMContentLoaded, readystatechange,
	 *  window load, window.onload =) never runs there. Here form code runs while the page loads: between nuNextLoadGuard(true) and (false)
	 *  (pages/nuform.php: around the scripts of HTML objects and the form's JavaScript) such handlers are dropped as in Forte, with a
	 *  console note (0.8.983, the owner's decision; Compatibility check G11). Setup → Header is not guarded – it is Forte's page code. */
	var LG = {on: false, dropped: 0, onload: null, onrs: null};
	function lgNote(what) {
		LG.dropped++;
		try { console.info('nuBuilder Next: the form\'s code waits for "' + what + '" – as in nuBuilder Forte the page has already loaded, so the handler does not run. Put the code into nuOnLoad().'); } catch (x) { /* no console */ }
	}
	function lgAdd(target, original) {
		return function (type, fn, opt) {
			var t = String(type);
			if (LG.on && (t === 'DOMContentLoaded' || t === 'readystatechange' || (t === 'load' && target === window))) { lgNote(t); return undefined; }
			return original.call(this, type, fn, opt);
		};
	}
	api('nuNextLoadGuard', function (on) {
		if (on && !LG.on) {
			LG.on = true; LG.onload = window.onload; LG.onrs = document.onreadystatechange;
			window.addEventListener = lgAdd(window, EventTarget.prototype.addEventListener);
			document.addEventListener = lgAdd(document, EventTarget.prototype.addEventListener);
		} else if (!on && LG.on) {
			LG.on = false;
			delete window.addEventListener; delete document.addEventListener;
			if (window.onload !== LG.onload) { lgNote('window.onload'); window.onload = LG.onload; }
			if (document.onreadystatechange !== LG.onrs) { lgNote('document.onreadystatechange'); document.onreadystatechange = LG.onrs; }
		}
		return LG.dropped;
	});
	api('nuNextRunDeferred', function (root) {
		var list = Array.prototype.slice.call((root || document).querySelectorAll('script[type="text/nu-deferred"]:not([data-nu-ran])'));
		(function next() {
			var old = list.shift();
			if (!old) return;
			old.setAttribute('data-nu-ran', '1');
			var s = document.createElement('script');
			Array.prototype.forEach.call(old.attributes, function (a) { if (a.name !== 'type' && a.name !== 'data-nu-type' && a.name !== 'data-nu-ran') s.setAttribute(a.name, a.value); });
			var tp = old.getAttribute('data-nu-type');
			if (tp) s.type = tp;
			s.setAttribute('data-nu-source', 'html object');
			if (old.src) {
				s.async = false;
				s.onload = s.onerror = next;
				old.parentNode.insertBefore(s, old.nextSibling);
				return;
			}
			s.text = old.text;
			try { old.parentNode.insertBefore(s, old.nextSibling); } catch (e) { reportError(e.message, 'html object'); }
			next();
		})();
	});

	// =================================================================== subforms
	api('nuSubformObject', function (sf) {
		var info = (N.subforms || {})[sf] || {fields: []};
		var o = {id: sf, foreign_key: info.foreign_key, primary_key: info.primary_key, object_id: info.object_id, table: info.table,
			fields: ['ID'].concat(info.fields).concat(['nuDelete']), rows: [], deleted: [], edited: [], columns: [], chartData: null, chartDataPivot: null};
		$('#' + sf + ' .nuSubformRow').each(function () {
			var p = this.getAttribute('data-nu-prefix');
			var pk = this.getAttribute('data-nu-primary-key') || '';
			var row = [pk || '-1'], ed = [0];                                         // a new row's ID is -1, as nuBuilder Forte (0.8.956)
			// checkboxes as 0 / 1 and an edited mark for nuDelete too, as nuBuilder Forte (0.8.984)
			info.fields.forEach(function (f) { var el = g(p + f); row.push(el && el.type === 'checkbox' ? (el.checked ? 1 : 0) : rawValue(el)); ed.push(el && el.classList.contains('nuEdited') ? 1 : 0); });
			var del = g(p + 'nuDelete');
			var isBlankNew = pk === '' && !this.classList.contains('nuRowEdited');
			var d = (del && del.checked) || isBlankNew ? 1 : 0;
			row.push(d); ed.push(0);
			o.rows.push(row); o.deleted.push(d); o.edited.push(ed);
		});
		return o;
	});

	// nuBuilder Forte's nuAddRow(sub-form[, setFocus]): the blank last row becomes a row (a change on its first field – it gets its edit
	// mark, its afterinsertrow event ran when it was drawn) and a new blank row follows; the focus goes to that new row (0.8.965 – before,
	// a second blank row was appended). Without a blank row (adding switched off …) a row is appended as before, when there is a template.
	api('nuAddRow', function (sf, setFocus) {
		var last = $('#' + sf + ' .nuSubformBody').children('.nuSubformRow').last()[0];
		var o = nuSubformObject(sf), f = o.fields[1];
		var el = last && last.classList.contains('nuNewRow') && f ? g(last.getAttribute('data-nu-prefix') + f) : null;
		if (!el) return appendRow(sf);
		$(el).trigger('change');
		if (!last.classList.contains('nuRowEdited')) markEdited(el);       // a field the change handler skips (lookup code …)
		var next = last.nextElementSibling;
		if (setFocus !== false && next && next.getAttribute) {
			var np = next.getAttribute('data-nu-prefix'), ne = g(np + f + 'code') || g(np + f);   // a lookup: its code field
			if (ne && ne.focus) ne.focus();
		}
		return last;
	});
	function appendRow(sf) {
		var tpl = g(sf + 'Template');
		if (!tpl) return null;
		var body = $('#' + sf + ' .nuSubformBody')[0];
		var n = $(body).children('.nuSubformRow').length;
		var html = tpl.innerHTML.split('__ROW__').join(nuPad3(n));
		$(body).append(html);
		var row = g(sf + nuPad3(n));
		nuNextRunDeferred(row);
		initSelects(row);
		fitButtons(row);
		var ev = ((N.events || {})[sf] || {}).afterinsertrow;
		if (ev) runSnippet(ev, {target: row}, row);
		return row;
	}

	api('nuSubformRowObject', function (id, field) {
		var p = $('#' + id).attr('data-nu-prefix') || '';
		return $('#' + p + field);
	});
	api('nuSubformRowId', function (el) { return $(el).closest('.nuSubformRow').attr('data-nu-primary-key'); });
	api('nuSubformRowNumber', function (id) { var p = $('#' + id).attr('data-nu-prefix') || ''; return parseInt(p.slice(-3), 10); });
	api('nuSubformColumnArray', function (sf, field, includeDeleted) {
		var o = nuSubformObject(sf), c = o.fields.indexOf(field), a = [];
		o.rows.forEach(function (r, i) { if (includeDeleted || !o.deleted[i]) a.push(r[c]); });
		return a;
	});
	api('nuSubformColumnUnique', function (sf, field, label) {
		var a = nuSubformColumnArray(sf, field).filter(function (v) { return v !== ''; });
		return a.length === new Set(a).size ? true : (nuTranslate('Duplicate values in') + ' ' + (label || field));
	});
	// nuBuilder Forte: without a field the whole sub-form (also its add / delete controls); enable === false disables
	function sfToggle(sf, field, on) {
		$('#' + sf + ' [data-nu-field' + (field ? '="' + field + '"' : '') + ']').each(function () { nuEnable(this.id, on); });
		if (!field) $('#' + sf).toggleClass('nuSubformDisabled', !on).find('input.nuSubformDelete').prop('disabled', !on);
	}
	api('nuSubformDisable', function (sf, field) { sfToggle(sf, field, false); });
	api('nuSubformEnable', function (sf, field, enable) { sfToggle(sf, field, enable !== false); });
	api('nuSubformHideColumn', function (sf, field) { var i = $('#title_' + sf + field).index(); $('#' + sf + ' tr').each(function () { $(this).children().eq(i).hide(); }); });

	// =================================================================== calendar (native date picker)
	api('nuPopupCalendar', function (el, initial) {
		if (!el || el.readOnly || el.disabled) return;
		var fmt = el.getAttribute('data-nu-format') || 'D|yyyy-mm-dd';
		var picker = g('nuCalendarPicker');
		if (!picker) {
			picker = document.createElement('input');
			picker.type = 'date';
			picker.id = 'nuCalendarPicker';
			picker.tabIndex = -1;
			picker.setAttribute('aria-hidden', 'true');
			picker.className = 'nuCalendarPicker';
			document.body.appendChild(picker);
		}
		var start = removeFormatting(el.value || initial || '', fmt);
		if (!/^\d{4}-\d{2}-\d{2}/.test(start) && initial) start = removeFormatting(initial, fmt);
		picker.value = /^\d{4}-\d{2}-\d{2}/.test(start) ? start.substr(0, 10) : '';
		// nuBuilder: nuOnSetCalendarOptions(id, {options}) – the browser's own date picker knows only the range:
		// minDate / maxDate (or min / max) as Date, yyyy-mm-dd or in the field's format; other options are ignored
		picker.removeAttribute('min'); picker.removeAttribute('max');
		if (typeof window.nuOnSetCalendarOptions === 'function') {
			var cal = {options: {format: fmt.substring(2), todayHighlight: true, clearBtn: true, autohide: true}};
			var user = callHook('nuOnSetCalendarOptions', el.id, cal);
			var co = $.extend({}, user && typeof user === 'object' ? user : {}, cal.options);
			var ymdOf = function (x) {
				if (!x) return '';
				if (x instanceof Date) return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
				x = String(x);
				return /^\d{4}-\d{2}-\d{2}/.test(x) ? x.substr(0, 10) : String(removeFormatting(x, fmt)).substr(0, 10);
			};
			var mn = ymdOf(co.minDate || co.min), mx = ymdOf(co.maxDate || co.max);
			if (/^\d{4}-\d{2}-\d{2}$/.test(mn)) picker.min = mn;
			if (/^\d{4}-\d{2}-\d{2}$/.test(mx)) picker.max = mx;
		}
		var r = el.getBoundingClientRect();
		picker.style.top = (r.bottom + window.scrollY) + 'px';
		picker.style.left = (r.left + window.scrollX) + 'px';
		picker.onchange = function () {
			if (!picker.value) return;
			var time = /\d{2}:\d{2}(:\d{2})?$/.exec(removeFormatting(el.value, fmt));
			el.value = addFormatting(picker.value + (time ? ' ' + time[0] : ''), fmt);
			$(el).trigger('change');
		};
		try { picker.showPicker(); } catch (x) { picker.focus(); }
	});
	window.nuSetCalendarOnTop = window.nuSetCalendarOnTop || function () {};

	// =================================================================== lookups
	function lookupInfo(target) {
		var code = g(target + 'code') || g(target + 'button');
		return code ? {object: code.getAttribute('data-nu-object-id'), form: code.getAttribute('data-nu-lookup-form'), prefix: code.getAttribute('data-nu-prefix') || ''} : null;
	}

	function setLookup(target, res) {
		var info = lookupInfo(target) || {prefix: ''};
		var hidden = g(target);
		if (!hidden) return;
		hidden.value = res.pk || '';
		if (g(target + 'code')) g(target + 'code').value = res.code || '';
		if (g(target + 'description')) g(target + 'description').value = res.description || '';
		markEdited(hidden);
		$(hidden).trigger('change');
		applyResult(res, info.prefix);
		var field = hidden.getAttribute('data-nu-field');
		var key = info.prefix ? info.prefix.replace(/\d{3}$/, '') + '.' + field : field;
		var js = ((N.events || {})[key] || {}).lookup;
		if (res.pk && js) runSnippet(js, {target: hidden}, hidden);
		calculate();
		// nuBuilder: (id, prefix, values) – global hook first; called for every lookup (nuBuilder: only in subform rows)
		callHook('nuOnLookupPopulatedGlobal', target, info.prefix || '', res);
		callHook('nuOnLookupPopulated', target, info.prefix || '', res);
	}

	api('nuGetLookupId', function (pk, target) {
		var info = lookupInfo(target);
		if (!info) return;
		window.nuLOOKUPSTATE[info.object] = 'looking';
		return call('lookupid', {object_id: info.object, pk: pk, values: mainValues()}).then(function (res) {
			window.nuLOOKUPSTATE[info.object] = 'found';
			if (res.ok === false) { nuDisplayError(res); return; }
			setLookup(target, res.count ? res : {pk: '', code: '', description: ''});
		});
	});

	api('nuChooseOneLookupRecord', function (e, data) {
		var target = (e.target && e.target.getAttribute('data-nu-target')) || data.target;
		if (data.count === 1) setLookup(target, data);
		else nuBuildLookup(target, e.target ? e.target.value : '');
	});

	api('nuGetLookupCode', function (e) {
		var t = e.target.getAttribute('data-nu-target');
		if (!e.target.value) {
			window.nuLOOKUPCLEARING = true;
			setLookup(t, {pk: '', code: '', description: ''});
			window.nuLOOKUPCLEARING = false;
			return;
		}
		var last = nuFORM.getCurrent();
		last.call_type = 'getlookupcode';
		last.object_id = e.target.getAttribute('data-nu-object-id');
		last.target = t;
		last.code = e.target.value;
		last.hash = nuHashFromEditForm();
		window.nuLOOKUPSTATE[last.object_id] = 'looking';
		nuAjax(last, function (data) {
			window.nuSERVERRESPONSELU = data;
			if (!nuDisplayError(data)) nuChooseOneLookupRecord(e, data);
		});
	});

	api('nuBuildLookup', function (target, search) {
		var info = lookupInfo(target);
		if (!info || !info.form) return;
		var modal = g('nuLookupModal');
		go(function () { return formUrl(info.form, '', {lookup: target, lo: info.object, q: search || ''}); }, function (url) { $(modal).find('iframe').attr('src', url); });
		nuBs.modal(modal).show();
	});

	window.nuNextLookupChosen = function (target, pk) {
		nuBs.modal(g('nuLookupModal')).hide();
		nuGetLookupId(pk, target);
	};
	window.nuNextCloseLookup = function () {
		['nuLookupModal', 'nuPopupModal'].forEach(function (id) { var m = g(id); if (m && m.classList.contains('show')) nuBs.modal(m).hide(); });
	};
	api('nuClosePopup', function () {
		try { if (window.parent !== window && window.parent.nuNextCloseLookup) window.parent.nuNextCloseLookup(); } catch (e) { /* ignore */ }
	});

	$(document).on('change', '.nuLookupCode', function (e) { window.nuGetLookupCode(e); });
	$(document).on('click', '.nuLookupButton', function () { nuBuildLookup(this.getAttribute('data-nu-target')); });
	$(document).on('keydown', '.nuLookupCode', function (e) { if (e.key === 'ArrowDown' && e.altKey) { e.preventDefault(); nuBuildLookup(this.getAttribute('data-nu-target'), this.value); } });

	// =================================================================== nuScroll (0.8.369)
	// nuBuilder's input type nuScroll (nuFORM.scrollList): ↑ / ↓ step through the object's list (JavaScript, usually ['a', 'b']), text
	// that is not in the list can still be typed. The list is also offered as suggestions (<datalist>): phones show it when the field
	// is tapped, desktops as a small arrow in the field. One datalist per field, shared by the rows of a sub-form.
	function scrollList(el) {
		if (el._nuScroll !== undefined) return el._nuScroll;
		var src = el.getAttribute('data-nu-scroll') || '', l = [];
		if (src.trim() !== '') {
			try { l = (new Function('return (' + src + '\n);'))(); } catch (x) { reportError(x.message, 'nuScroll ' + el.id); }
		}
		if (!Array.isArray(l)) l = [];
		return (el._nuScroll = l.map(function (v) { return v === null || v === undefined ? '' : String(v); }));
	}
	function initScroll(el) {
		if (el._nuScrollInit) return;
		el._nuScrollInit = true;
		var l = scrollList(el);
		if (!l.length || el.hasAttribute('list')) return;
		var id = 'nuScrollList_' + (el.getAttribute('data-nu-field') || el.id);
		if (!g(id)) {
			var dl = document.createElement('datalist');
			dl.id = id;
			l.forEach(function (v) { var o = document.createElement('option'); o.value = v; dl.appendChild(o); });
			document.body.appendChild(dl);
		}
		el.setAttribute('list', id);
		el.setAttribute('autocomplete', 'off');
	}
	$(document).on('keydown', '.nuScroll', function (e) {
		if ((e.key !== 'ArrowUp' && e.key !== 'ArrowDown') || e.altKey || e.ctrlKey || e.metaKey || this.readOnly || this.disabled) return;
		var l = scrollList(this);
		if (!l.length) return;
		e.preventDefault();
		var i = l.indexOf(this.value);
		// a value that is not in the list: ↓ starts at the first entry, ↑ at the last (nuBuilder skipped the first one)
		i = i < 0 ? (e.key === 'ArrowDown' ? 0 : l.length - 1) : (i + (e.key === 'ArrowDown' ? 1 : l.length - 1)) % l.length;
		$(this).val(l[i]).trigger('change');
	});
	$(document).on('focusin', '.nuScroll', function () { initScroll(this); });
	$(function () { $('.nuScroll').each(function () { initScroll(this); }); });

	// =================================================================== save / delete / clone
	/** The value the server drew into a field ('' when it came empty) – the browser fills colour, range and selects by itself. */
	function startValue(el) {
		if (el.type === 'checkbox') return el.defaultChecked ? '1' : '';
		if (el.tagName === 'SELECT') { for (var i = 0; i < el.options.length; i++) if (el.options[i].defaultSelected) return el.options[i].value; return ''; }
		if (el.tagName === 'TEXTAREA') return el.defaultValue;
		return el.getAttribute('value') || '';
	}
	/** nuBuilder saves a field of a new record only when it was edited or came with a value (core/nuform.js marks those as edited):
	 *  an untouched empty field is not sent, so its column keeps the default / NULL (0.8.364). */
	function untouchedNew(el) { return !el.classList.contains('nuEdited') && startValue(el) === ''; }

	function subformPayload() {
		var out = {};
		Object.keys(N.subforms || {}).forEach(function (sf) {
			var info = N.subforms[sf], rows = [];
			$('#' + sf + ' .nuSubformRow').each(function () {
				var p = this.getAttribute('data-nu-prefix'), values = {}, edited = [], newRow = !this.getAttribute('data-nu-primary-key');
				info.fields.forEach(function (f) {
					var el = g(p + f);
					if (!el) return;
					if (newRow && untouchedNew(el)) return;                     // new row: as nuBuilder (0.8.364)
					values[f] = rawValue(el);
					if (el.classList.contains('nuEdited')) edited.push(f);
				});
				var del = g(p + 'nuDelete');
				var pk = this.getAttribute('data-nu-primary-key') || '';
				if (pk === '' && !edited.length) return;                      // untouched blank row
				rows.push({pk: pk, values: values, edited: edited, deleted: del && del.checked ? 1 : 0});
			});
			out[sf] = {rows: rows};
		});
		return out;
	}

	api('nuSaveAction', function (close) {      // nuBuilder: nuSaveAction(true) = Save & Close (back to the previous breadcrumb)
		syncEditors();
		var then = N.afterSave; N.afterSave = null;       // set by the leave dialog: where to go after a successful save
		var incomplete = $('#nuEditForm input[data-nu-format^="T|"]').filter(function () {
			if (this.closest('.nuSubformRow.nuRowDeleted')) return false;
			applyFormat(this, false);
			return !NuFormatLib.maskComplete(this.value, this.getAttribute('data-nu-format'));
		});
		if (incomplete.length) {
			var el0 = incomplete[0], o = (N.objects || {})[el0.id] || {};
			nuMessage([esc(String(M.mask_incomplete || '').replace('%s', o.label || el0.getAttribute('data-nu-field') || el0.id))]);
			try { el0.focus(); } catch (x) { /* ignore */ }
			return;
		}
		if (window.nuBeforeSaveGlobal) {                                    // nuBuilder: the global hook (Setup → header) first, false cancels
			var okG;
			try { okG = window.nuBeforeSaveGlobal(); } catch (x) { reportError(x.message, 'nuBeforeSaveGlobal'); return; }
			if (okG === false) return;
		}
		if (window.nuBeforeSave) {
			var ok;
			try { ok = window.nuBeforeSave(); } catch (x) { reportError(x.message, 'nuBeforeSave'); return; }
			if (ok === false) return;                                       // only false stops the save – a nuBeforeSave without return saves (Forte, 0.8.960)
		}
		var btn = $('#nuSaveButton').prop('disabled', true);
		var edited = $('#nuEditForm .nuEdited').map(function () { return this.getAttribute('data-nu-prefix') ? null : this.getAttribute('data-nu-field'); }).get();
		var values = mainValues(), untouched = [];
		if (N.record_id === '-1' && !N.cloning) {                          // new record: untouched empty fields are not sent (as nuBuilder, 0.8.364)
			Object.keys(values).forEach(function (f) {
				var el = g(f), o = (N.objects || {})[f];
				if (o && o.type === 'editor') { if (edited.indexOf(f) < 0) edited.push(f); return; }   // nuBuilder's editor marks itself edited: saved as ''
				if (el && untouchedNew(el)) { delete values[f]; untouched.push(f); }
			});
		}
		var subs = subformPayload();
		// untouched: names only – still hash variables ('') of Before / After Save, as Forte's hash holds every field (0.8.977)
		var payload = {values: values, edited: edited, untouched: untouched, subforms: subs, clone: !!N.cloning, clone_from: N.cloning ? (N.cloneFrom || '') : ''};
		if (N.cc && !N.cloning && N.record_id !== '-1') payload.cc = ccPayload(edited, subs);        // concurrent edits (0.8.401)
		if (window.nuNextRules) {                                           // validation rules (0.8.812): errors at once, the server checks again
			var pre = window.nuNextRules.check(values, subs);
			if (pre.errors.length) { btn.prop('disabled', false); nuDisplayError({errors: pre.errors}); return; }
		}
		var send = function () { call('save', payload).then(function (res) {
			btn.prop('disabled', false);
			if (!res.ok && res.conflict) { N.afterSave = then; showConflict(res.conflict, close); return; }
			if (!res.ok && res.rules_warn && res.rules_warn.length && !(res.errors || []).length && window.nuNextRules) {   // warnings: save anyway?
				window.nuNextRules.askWarnings(res.rules_warn, function () {
					payload.rules_ok = (payload.rules_ok || []).concat(res.rules_warn.map(function (w) { return w.id; }));
					btn.prop('disabled', true);
					send();
				});
				return;
			}
			if (!res.ok) { applyResult(res); return; }
			try { if (res.js && res.js.length) sessionStorage.setItem('nuNextPendingJs', JSON.stringify(res.js)); } catch (x) { /* ignore */ }
			N.edited = false;
			var afterSaveNow = function () {
				callHook('nuAfterSaveGlobal');
				if (window.nuAfterSave) { try { window.nuAfterSave(); } catch (x) { reportError(x.message, 'nuAfterSave'); } }
			};
			if (N.lookup && N.is_new && window.parent !== window && window.parent.nuNextLookupChosen) {   // added from a lookup: choose it
				afterSaveNow();
				try { window.parent.nuNextLookupChosen(N.lookup, res.record_id); return; } catch (x) { reportError(x.message, 'lookup'); }
			}
			if (then) { afterSaveNow(); if (typeof then === 'function') then(); else window.location.href = then; return; }
			if (close === true) {
				afterSaveNow();
				var stay = false;                                              // nuBuilder: nuCloseAfterSaveGlobal() returning false keeps the form open
				if (typeof window.nuCloseAfterSaveGlobal === 'function') { try { stay = window.nuCloseAfterSaveGlobal(true) === false; } catch (x) { reportError(x.message, 'nuCloseAfterSaveGlobal'); } }
				var bc = N.breadcrumbs || [];
				if (!stay) for (var bi = bc.length - 2; bi >= 0; bi--) if (bc[bi].url) { window.location.replace(bc[bi].url); return; }
				if (stay) return;
			}
			// nuBuilder runs nuAfterSaveGlobal / nuAfterSave on the rebuilt form: the reloaded page calls them after nuOnLoad (0.8.383)
			var openId = res.open_id !== undefined && res.open_id !== null ? String(res.open_id) : res.record_id;   // nuReturnNewRecord() in After Save (0.8.443)
			try { sessionStorage.setItem('nuNextAfterSave', N.form.id + '|' + openId); } catch (x) { afterSaveNow(); }
			winCarrySave(openId);
			var u = new URL(window.location.href);
			u.searchParams.set('r', openId);
			u.searchParams.set('saved', '1');
			window.location.replace(u.toString());
		}, function (err) { btn.prop('disabled', false); nuMessage([esc(err.message)]); }); };
		send();
	});

	// nuBuilder Forte keeps the page after a save (one-page app): variables form code put on window while the user worked (window.x = …
	// in an event) are still there for nuAfterSave. Here the save reloads the page – such variables with plain values (text, number,
	// yes / no, small JSON) travel with it; what the page's own scripts define again is left alone.
	var winKeys0 = null;
	function winCarrySave(openId) {
		if (!winKeys0) return;
		var vars = {}, size = 0;
		Object.keys(window).forEach(function (k) {
			if (winKeys0[k] || /^(on|webkit|nuNext|Nu[A-Z]|CodeMirror|tinymce|tinyMCE|Uppy|ApexCharts|google)/.test(k)) return;   // libraries loaded later (NuCodeTools …) are no form variables
			var v; try { v = window[k]; } catch (x) { return; }
			var t = typeof v;
			if (t === 'function' || t === 'undefined' || t === 'symbol' || t === 'bigint') return;
			if (v !== null && t === 'object') { var proto = Object.getPrototypeOf(v); if (proto !== Object.prototype && proto !== Array.prototype) return; }
			if (v !== null && t === 'object' && hasFn(v, 0)) return;            // an object with functions would come back without them
			var j; try { j = JSON.stringify(v); } catch (x) { return; }
			if (j === undefined || (size += j.length) > 50000) return;
			vars[k] = v;
		});
		try { if (Object.keys(vars).length) sessionStorage.setItem('nuNextWinCarry', JSON.stringify({to: N.form.id + '|' + openId, vars: vars})); } catch (x) { /* storage full / blocked */ }
	}
	function hasFn(v, d) {
		if (d > 4) return false;
		for (var k in v) { if (!Object.prototype.hasOwnProperty.call(v, k)) continue; var x = v[k]; if (typeof x === 'function') return true; if (x && typeof x === 'object' && hasFn(x, d + 1)) return true; }
		return false;
	}
	function winCarryRestore() {
		winKeys0 = {};
		Object.keys(window).forEach(function (k) { winKeys0[k] = 1; });
		var raw = null;
		try { raw = sessionStorage.getItem('nuNextWinCarry'); if (raw !== null) sessionStorage.removeItem('nuNextWinCarry'); } catch (x) { return; }
		if (!raw || N.mode !== 'edit') return;
		try {
			var c = JSON.parse(raw);
			if (!c || c.to !== N.form.id + '|' + N.record_id) return;
			Object.keys(c.vars || {}).forEach(function (k) { if (!(k in window)) window[k] = c.vars[k]; });
		} catch (x) { /* broken entry */ }
	}

	api('nuDeleteAction', function () {
		$.confirm || typeof window.nuConfirmAsk === 'function' ? confirmDelete() : (window.confirm(M.delete_confirm) && doDelete());
	});
	function confirmDelete() {
		// our own dialog (review E4): names the record and the sub-form rows deleted with it, Cancel has the focus
		if (typeof window.nuConfirmAsk === 'function') {
			var bc = N.breadcrumbs || [], name = bc.length ? String(bc[bc.length - 1].title || '').trim() : '';
			var details = [];
			Object.keys(N.subforms || {}).forEach(function (sf) {
				var n = $('#' + sf + ' .nuSubformRow[data-nu-primary-key]').filter(function () { return this.getAttribute('data-nu-primary-key') !== ''; }).length;
				if (!n) return;
				var lbl = $('#label_' + sf).text().trim() || sf;
				details.push(ccFmt(M.delete_with, String(n), lbl));
			});
			window.nuConfirmAsk(name && name !== (N.form && N.form.description) ? ccFmt(M.delete_named, name) : M.delete_confirm, doDelete, M.delete, 'btn btn-danger',
				{title: M.delete_title, details: details, note: M.delete_undo, cancelFirst: true});
			return;
		}
		$.confirm({
			title: M.delete, content: M.delete_confirm, useBootstrap: false, boxWidth: '360px', type: 'red',
			buttons: {confirm: {text: M.delete, btnClass: 'btn-red', action: doDelete}, cancel: {text: M.cancel}}
		});
	}
	function doDelete(force) {
		if (force !== true && window.nuBeforeDelete && window.nuBeforeDelete() === false) return;
		call('delete', N.cc ? (force === true ? {cc_force: 1} : {cc_h: N.cc.h}) : {}).then(function (res) {
			if (!res.ok && res.conflict && res.conflict.delete) {            // changed since it was opened (0.8.401)
				var q = res.conflict.by ? ccFmt(M.cc_delete_by, res.conflict.by, res.conflict.at) : M.cc_delete_anon;
				if (window.confirm(q)) doDelete(true);
				return;
			}
			if (!res.ok) { applyResult(res); return; }
			N.edited = false;
			window.last_action = 'delete';                                    // nuBuilder sets it before its after-delete hooks
			callHook('nuAfterDeleteGlobal');
			callHook('nuAfterDelete');
			try { sessionStorage.setItem('nuNextToast', JSON.stringify([M.deleted || 'Record deleted.', 'success'])); } catch (x) { /* ignore */ }
			var bc = N.breadcrumbs || [];
			var back = bc.length > 1 && bc[bc.length - 2].url ? bc[bc.length - 2].url : N.base + '?p=home';
			window.location.replace(back);
		});
	}

	// =================================================================== concurrent edits (0.8.401, core/form/nuconflict.php)
	function ccFmt(t) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return String(t || '').replace(/%s/g, function () { return a[i++] === undefined ? '' : a[i - 1]; }); }
	N.ccOver = {};                                                        // signatures taken over after "keep mine" / "delete anyway"
	/** Signatures of the edited fields (as opened) – the server compares them with the database. */
	function ccPayload(edited, subs) {
		var out = {f: {}, sf: {}}, cc = N.cc;
		edited.forEach(function (f) {
			var k = '||' + f, sig = N.ccOver[k] !== undefined ? N.ccOver[k] : (cc.f || {})[String(f).toLowerCase()];
			if (sig !== undefined) out.f[f] = sig;
		});
		Object.keys(subs || {}).forEach(function (sf) {
			var rowsCc = (cc.sf || {})[sf] || {}, o = {};
			(subs[sf].rows || []).forEach(function (r) {
				var info = r.pk ? rowsCc[r.pk] : null;
				if (!info) return;
				if (r.deleted) { var hk = sf + '|' + r.pk + '|'; o[r.pk] = {h: N.ccOver[hk] !== undefined ? N.ccOver[hk] : info.h}; return; }
				var f = {};
				(r.edited || []).forEach(function (fd) {
					var k = sf + '|' + r.pk + '|' + fd, sig = N.ccOver[k] !== undefined ? N.ccOver[k] : (info.f || {})[String(fd).toLowerCase()];
					if (sig !== undefined) f[fd] = sig;
				});
				if (Object.keys(f).length) o[r.pk] = {f: f};
			});
			if (Object.keys(o).length) out.sf[sf] = o;
		});
		return out;
	}
	function ccRow(c) { return c.sf ? $('#' + c.sf + ' .nuSubformRow').filter(function () { return this.getAttribute('data-nu-primary-key') === String(c.pk); })[0] : null; }
	function ccEl(c) {
		if (!c.sf) return g(c.field);
		var row = ccRow(c), p = row ? row.getAttribute('data-nu-prefix') : '';
		return p ? g(p + c.field) : null;
	}
	/** A value as the user sees it: text of a select option, ✓ for a ticked checkbox, (empty). */
	function ccShow(el, v) {
		v = v === null || v === undefined ? '' : String(v);
		if (el && el.tagName === 'SELECT') { for (var i = 0; i < el.options.length; i++) if (el.options[i].value === v) { v = el.options[i].text; break; } }
		else if (el && el.type === 'checkbox') v = v === '1' ? '✓' : '–';
		return v === '' ? '<span class="text-body-secondary fst-italic">' + esc(M.cc_empty) + '</span>' : esc(v);
	}
	function showConflict(c, close) {
		if (c.gone) { nuMessage([esc(M.cc_record_gone)]); return; }
		var intro = c.by ? ccFmt(M.cc_by, c.by, c.at) : M.cc_anon, rows = '', notes = '';
		(c.fields || []).forEach(function (x, i) {
			var where = x.sf ? ccFmt(M.cc_row, x.sf_label, x.row) : '';
			if (x.gone) { notes += '<li>' + esc(ccFmt(M.cc_row_gone, x.sf_label, x.row)) + '</li>'; return; }
			if (x.deleted_changed) {
				notes += '<li>' + esc(ccFmt(M.cc_row_changed, x.sf_label, x.row)) + ' <span class="text-nowrap">'
					+ '<label class="me-2"><input type="radio" name="nuCc' + i + '" value="mine" checked> ' + esc(M.cc_delete_row) + '</label>'
					+ '<label><input type="radio" name="nuCc' + i + '" value="theirs"> ' + esc(M.cc_keep_row) + '</label></span></li>';
				return;
			}
			var el = ccEl(x);
			var orig = el ? startValue(el) : '', mine = el ? rawValue(el) : '';
			rows += '<tr><td class="fw-semibold">' + esc(x.label) + (where ? '<div class="small text-body-secondary">' + esc(where) + '</div>' : '') + '</td>'
				+ '<td>' + ccShow(el, orig) + '</td><td>' + ccShow(el, mine) + '</td><td>' + ccShow(el, x.theirs) + '</td>'
				+ '<td class="text-nowrap"><label class="me-2"><input type="radio" name="nuCc' + i + '" value="mine" checked> ' + esc(M.cc_keep_mine) + '</label>'
				+ '<label><input type="radio" name="nuCc' + i + '" value="theirs"> ' + esc(M.cc_keep_theirs) + '</label></td></tr>';
		});
		var html = '<div id="nuConflict"><p>' + esc(intro) + '</p>'
			+ (rows ? '<div class="table-responsive"><table class="table table-sm align-middle small mb-2"><thead><tr><th>' + esc(M.cc_field) + '</th><th>' + esc(M.cc_original) + '</th><th>' + esc(M.cc_yours)
				+ '</th><th>' + esc(M.cc_theirs) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '')
			+ (notes ? '<ul class="small mb-2">' + notes + '</ul>' : '')
			+ '<p class="small text-body-secondary mb-0">' + esc(M.cc_kept) + '</p></div>';
		var resolve = function ($box) {
			(c.fields || []).forEach(function (x, i) {
				var pick = $box.find('input[name="nuCc' + i + '"]:checked').val();
				if (x.gone) {                                                  // the row is gone: its changes are dropped
					var row = ccRow(x);
					if (row) $(row).find('.nuEdited').removeClass('nuEdited');
					return;
				}
				if (x.deleted_changed) {
					if (pick === 'theirs') { var rw = ccRow(x), d = rw ? g(rw.getAttribute('data-nu-prefix') + 'nuDelete') : null; if (d) { d.checked = false; $(d).trigger('change'); } }
					else N.ccOver[x.sf + '|' + x.pk + '|'] = x.h;
					return;
				}
				if (pick === 'theirs') { var el = ccEl(x); if (el) el.classList.remove('nuEdited'); }   // not written: the saved value stays
				else N.ccOver[(x.sf || '') + '|' + (x.sf ? x.pk : '') + '|' + x.field] = x.sig;
			});
			nuSaveAction(close);
		};
		var reload = function () { N.edited = false; $('#nuEditForm .nuEdited').removeClass('nuEdited'); window.location.reload(); };
		if ($.confirm) {
			$.confirm({
				title: M.cc_title, content: html, useBootstrap: false, boxWidth: Math.min(760, window.innerWidth - 24) + 'px', type: 'orange', columnClass: 'nuConflictBox',
				buttons: {
					save: {text: M.cc_save, btnClass: 'btn-orange', action: function () { resolve(this.$content); }},
					reload: {text: M.cc_reload, action: reload},
					cancel: {text: M.cancel}
				}
			});
		} else if (window.confirm(intro + '\n\n' + M.cc_save + '?')) resolve($(html));
	}

	// "also open by …" (D1): ping every 30 s while the tab is visible; others are shown above the form; never blocks
	if (N.presence && N.mode === 'edit' && N.record_id && N.record_id !== '-1') {
		var presenceUrl = N.base + '?p=formapi&presence=1';
		var ping = function (leave) {
			var body = JSON.stringify({action: 'presence', form_id: N.form.id, record_id: N.record_id, edited: !!N.edited, leave: leave ? 1 : 0});
			return fetch(presenceUrl, {method: 'POST', credentials: 'same-origin', keepalive: !!leave,
				headers: {'Content-Type': 'application/json', 'X-CSRF-Token': N.csrf, 'Accept': 'application/json'}, body: body})
				.then(function (r) { return r.json(); }).then(function (j) { if (!leave && j && j.ok) showPresence(j.others || []); }).catch(function () { /* ignore */ });
		};
		var showPresence = function (others) {
			var box = g('nuPresence');
			if (!others.length) { if (box) box.remove(); return; }
			if (!box) {
				box = document.createElement('div');
				box.id = 'nuPresence';
				box.className = 'alert alert-warning py-2 px-3 mb-3 small d-flex gap-2 align-items-start';
				box.setAttribute('role', 'status');
				var head = document.querySelector('.nuFormHeader');
				if (head && head.parentNode) head.parentNode.insertBefore(box, head.nextSibling); else document.body.insertBefore(box, document.body.firstChild);
			}
			box.innerHTML = '<i class="bi bi-people-fill mt-1"></i><div>' + others.map(function (o) {
				var who = o.name && o.name !== o.login ? o.name + ' (' + o.login + ')' : o.login;
				return esc(ccFmt(o.edited ? M.pr_editing : M.pr_open, who, o.since));
			}).join('<br>') + '</div>';
		};
		var lastPing = 0, lastEdited = false;
		var tick = function (force) {
			if (document.visibilityState === 'hidden') return;
			if (force || Date.now() - lastPing > 29000 || !!N.edited !== lastEdited) { lastPing = Date.now(); lastEdited = !!N.edited; ping(false); }
		};
		setTimeout(function () { tick(true); }, 300);
		setInterval(tick, 5000);                                         // pings every 30 s, at once when unsaved changes start
		document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') tick(true); });
		window.addEventListener('pagehide', function () { ping(true); });
	}

	api('nuCloneAction', function () {
		if (!N.cloning && N.record_id && N.record_id !== '-1') N.cloneFrom = N.record_id;   // field data access (0.8.480): locked values come from the server
		N.record_id = '-1';
		N.is_new = true;
		N.cloning = true;
		Object.keys(N.objects || {}).forEach(function (id) {
			var o = N.objects[id], el = g(id);
			if (!el) return;
			if (id === N.form.primary_key || !o.cloneable) {
				if (el.type === 'checkbox') el.checked = false; else if ('value' in el) el.value = '';
				if (o.type === 'lookup') { if (g(id + 'code')) g(id + 'code').value = ''; if (g(id + 'description')) g(id + 'description').value = ''; }
			}
			if ('value' in el && el.type !== 'button') el.classList.add('nuEdited');
		});
		$('#nuEditForm .nuSubformRow').each(function () {
			if (this.classList.contains('nuNewRow')) return;   // the blank "new" row stays blank
			this.setAttribute('data-nu-primary-key', '');
			$(this).find('[data-nu-field]').addClass('nuEdited');
			if (!this.classList.contains('nuNewRow')) this.classList.add('nuRowEdited');
		});
		$('#nuCloneButton, #nuDeleteButton, #nuNewButton').remove();
		nuSetTitle(nuTranslate('Clone'));
		// clearly visible: a badge in the breadcrumb and a highlighted form until the copy is saved
		$('.nuBreadcrumb').last().append($('<span class="badge text-bg-warning ms-2 nuCloneBadge"><i class="bi bi-copy me-1"></i></span>').append(document.createTextNode(M.clone_badge || 'Clone – not saved')));
		$('#nuEditForm').addClass('nuCloning');
		N.edited = true;
		$('#nuSaveButton').addClass('nuSaveEdited');
		nuToast(M.cloned || 'Record cloned – save to create it.', 'info');
		if (window.nuOnClone) window.nuOnClone();
	});

	// nuBuilder Forte: nuAttachSaveButtonTo(id, dx, dy, height, width, fontSize) – Save below the object (15 px gap + dy, left + dx);
	// the canvas of the tab grows when the button would end below it (0.8.633: was cut off)
	api('nuAttachSaveButtonTo', function (id, dx, dy, h, w, fs) {
		var b = g('nuSaveButton'), target = g('nuw_' + id) || g(id);
		if (!b || !target) return $(b);
		var holder = $('<div class="nuAttachedSave"></div>').append(b);
		$(target).after(holder);
		if (Number(w) > 0) b.style.width = Number(w) + 'px';
		if (Number(h) > 0) { b.style.height = Number(h) + 'px'; b.style.minHeight = '0'; b.style.paddingTop = '0'; b.style.paddingBottom = '0'; }
		if (fs !== undefined && fs !== null) { if (typeof fs === 'number') b.style.fontSize = fs + 'px'; else $(b).css(fs); }
		if (Number(w) > 0 || Number(h) > 0) {
			b.classList.add('nuSaveSized');                                     // one line in the given size; the icon goes when the label needs the room
			if (b.scrollWidth > b.clientWidth + 1) b.classList.add('nuSaveNoIcon');
		}
		if (target.classList.contains('nuAbs')) {
			var top = target.offsetTop + target.offsetHeight + 15 + (Number(dy) || 0), left = target.offsetLeft + (Number(dx) || 0);
			holder.addClass('nuAbs').css({top: top + 'px', left: left + 'px'});
			var canvas = target.closest('.nuAbsoluteCanvas');
			if (canvas) {
				var need = top + (Number(h) > 0 ? Number(h) : b.offsetHeight) + 15;
				if (canvas.offsetHeight < need) canvas.style.minHeight = need + 'px';
			}
		}
		return $(b);
	});

	// nuBuilder: nuSetSaveButtonPosition(top, left, height, width, fontSize or css) – the Save button moves onto the first tab of an
	// absolute layout (like nuBuilder: only shown there); other layouts keep it in the action bar (0.8.443)
	api('nuSetSaveButtonPosition', function (t, l, h, w, fs) {
		var b = g('nuSaveButton'), rec = g('nuRECORD');
		if (!b || !rec || !rec.classList.contains('nuLayout-absolute')) return $(b);
		var canvas = rec.querySelector('.nuTabPane .nuAbsoluteCanvas') || rec.querySelector('.nuTabPane');
		if (!canvas) return $(b);
		if (!w) w = b.offsetWidth;
		if (!h) h = b.offsetHeight;
		canvas.appendChild(b);
		$(b).addClass('nuSaveMoved').css({position: 'absolute', top: t + 'px', left: l + 'px', width: w + 'px', height: h + 'px', margin: 'unset'});
		if (fs !== undefined && fs !== null) { if (typeof fs === 'number') b.style.fontSize = fs + 'px'; else $(b).css(fs); }
		var need = Number(t) + Number(h) + 10;
		if (canvas.offsetHeight < need) canvas.style.minHeight = need + 'px';
		return $(b);
	});

	// nuBuilder Forte: nuBrowseRowsPerPageFilter(options) adds a "Rows" select to a browse list – here the list footer has a page size
	// menu already, so the call replaces the sizes of that menu for this form (0.8.604; Forte's default sizes without options;
	// insertBeforeTarget / customStyle of Forte's select are not needed). The chosen size stays in the address (?per=).
	api('nuBrowseRowsPerPageFilter', function (options) {
		if (N.mode !== 'browse') return;
		var menu = document.querySelector('.nuLfPerMenu'), tpl = menu && menu.querySelector('a.dropdown-item');
		if (!tpl) return;
		var sizes = (Array.isArray(options) && options.length ? options : [10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 150, 200])
			.map(Number).filter(function (n) { return n >= 1 && n <= 1000 && Math.floor(n) === n; });
		if (!sizes.length) return;
		var act = menu.querySelector('a.dropdown-item.active') || tpl;
		var cur = Number(new URL(act.getAttribute('href'), location.href).searchParams.get('per')) || 0;
		if (cur && sizes.indexOf(cur) < 0) sizes.push(cur);
		sizes = sizes.filter(function (n, i) { return sizes.indexOf(n) === i; }).sort(function (a, b) { return a - b; });
		var text = tpl.textContent, num = text.match(/\d+/);
		menu.innerHTML = '';
		sizes.forEach(function (n) {
			var u = new URL(tpl.getAttribute('href'), location.href);
			u.searchParams.set('per', String(n));
			var a = document.createElement('a');
			a.className = 'dropdown-item' + (n === cur ? ' active' : '');
			if (n === cur) a.setAttribute('aria-current', 'true');
			a.href = u.pathname.split('/').pop() + u.search + u.hash;
			a.textContent = num ? text.replace(num[0], String(n)) : String(n);
			var li = document.createElement('li');
			li.appendChild(a);
			menu.appendChild(li);
		});
	});

	// nuBuilder Forte's action buttons are <input type="button" value="…">: code reads and changes the label with .value / $(…).val()
	// (e.g. filter buttons that mark the chosen one with "✓ "). Ours are <button>s (the label may hold HTML), so value = the label as
	// text and a jQuery val hook for button.nuActionButton gets / sets the label.
	if ($.valHooks) {
		var prevHook = $.valHooks.button;
		$.valHooks.button = {
			get: function (el) {
				if (el.tagName === 'BUTTON' && el.classList.contains('nuActionButton')) return el.textContent.trim();   // not <input type=button> (same hook key)
				return prevHook && prevHook.get ? prevHook.get(el, 'value') : undefined;
			},
			set: function (el, v) {
				if (el.tagName === 'BUTTON' && el.classList.contains('nuActionButton')) { var lb = el.querySelector('.nuButtonLabel'); (lb || el).textContent = v; el.value = v; return true; }
				return prevHook && prevHook.set ? prevHook.set(el, v, 'value') : undefined;
			}
		};
	}
	// nuBuilder Forte 4.9: built-in buttons by id (Save, SaveClose, Delete, Clone, Add, Print, Back, Run, RunHidden,
	// BuildFastForm, BuildFastReport) get their label, action and icon when those are left out; icon = icon classes,
	// insertAfterElement = id of the element to put the button after (else the action bar); value / title translated
	var ACTION_DEFAULTS = {
		Save: ['Save', 'nuSaveAction()', 'fa-regular fa-floppy-disk'], SaveClose: ['Save & Close', 'nuSaveAction(true)', 'fa-regular fa-floppy-disk'],
		Delete: ['Delete', 'nuDeleteAction()', 'fa-regular fa-square-minus'], Add: ['', 'nuAddAction()', 'fa-solid fa-plus'],
		Print: ['', 'nuPrintAction()', 'fa-solid fa-list-ul'], Back: ['Back', 'nuBackAction(this)', 'fa-solid fa-chevron-left'],
		Clone: ['Clone', 'nuCloneAction()', 'fa-regular fa-clone'], BuildFastForm: ['Build Fast Form', 'nuRunPHPHidden("nu_run_fast_form", true)', 'fa-solid fa-bolt'],
		BuildFastReport: ['Build Fast Report', 'nuRunPHPHidden("nu_run_fast_report", true)', 'fa-solid fa-bolt'],
		RunHidden: ['Run', 'nuRunHiddenAction()', 'fa-regular fa-circle-play'], Run: ['Run', 'nuRunAction()', 'fa-regular fa-circle-play']
	};
	api('nuAddActionButton', function (id, value, func, title, icon, insertAfterElement) {
		var d = ACTION_DEFAULTS[id];
		if (d) {
			if (value === undefined || value === null) value = d[0];
			if (func === undefined || func === null) func = d[1];
			if (icon === undefined || icon === null) icon = d[2] + ' fa-width-auto fa-lg';
		}
		if (value && typeof value === 'object') value = nuIsMobile() && value.valueMobile !== undefined ? value.valueMobile : (value.value || '');
		value = value === undefined || value === null ? '' : nuTranslate(String(value));
		var cls = 'btn btn-outline-secondary nuActionButton' + (['Save', 'Add', 'Clone', 'Delete'].indexOf(id) >= 0 ? ' nu' + id + 'Button' : '');
		$('#nu' + id + 'Button.nuActionButton').remove();
		var b = $('<button type="button"></button>').addClass(cls).attr('id', 'nu' + id + 'Button').attr('title', title ? nuTranslate(String(title)) : '');
		if (value !== '') b.append($('<span class="nuButtonLabel"></span>').html(value));
		b.attr('value', b.text().trim());
		if (func) b.on('click', function (e) { if (typeof func === 'function') func(e); else runSnippet(func, e, this); });
		if (insertAfterElement && g(insertAfterElement)) b.insertAfter('#' + insertAfterElement); else $('#nuActionHolder').append(b);
		if (icon) nuAddInputIcon(b.attr('id'), icon);
		return b;
	});
	if (!window.nuBackAction) api('nuBackAction', function () { nuOpenPreviousBreadcrumb(); });
	api('nuRemoveActionButton', function (id) { $('#nu' + id + 'Button').remove(); });

	api('nuAddDatalist', function (id, data) {
		var el = g(id);
		if (!el) return;
		var listId = 'nuDatalist_' + id;
		$('#' + listId).remove();
		var dl = $('<datalist></datalist>').attr('id', listId);
		arr(data).forEach(function (d) { dl.append($('<option>').attr('value', Array.isArray(d) ? d[0] : d).text(Array.isArray(d) && d[1] !== undefined ? d[1] : '')); });
		$(el).after(dl).attr('list', listId).attr('autocomplete', 'off');
	});

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
	// column widths by dragging the title (0.8.650, as nuBuilder Forte) – window.nuColResize of assets/js/nuapp.js (also the system lists, 0.8.651):
	// kept for this browser tab per form and lookup (sessionStorage), restored after nuOnLoad (the widths of this tab win over the form's code);
	// the cells of a column = its data-nu-column elements (the browse cells have a max-width of their own); nuDisableBrowseResize() switches it off
	var BR = {ctl: null, off: false};
	function brTable() { return document.getElementById('nuBrowseTable'); }
	// the title and the table cells of a column (the Forte-style div.nuCell sits inside the td, 0.8.965)
	function brColCells(t, c) {
		var out = Array.prototype.slice.call(t.querySelectorAll('thead th[data-nu-col="' + c + '"]'));
		t.querySelectorAll('tbody div.nuCell[data-nu-column="' + c + '"]').forEach(function (d) { out.push(d.parentNode); });
		return out;
	}
	function brInit() {
		var t = brTable();
		if (BR.ctl || !t || N.mode !== 'browse' || typeof window.nuColResize !== 'function') return BR.ctl;
		BR.ctl = window.nuColResize(t, {key: 'nunext-browse-w:' + N.form.id + (N.lookup ? ':lookup' : ''), titles: 'thead th.nuBrowseTitle',
			id: function (th) { return th.getAttribute('data-nu-col'); }, cells: function (c) { return brColCells(t, c); },
			fixedClass: 'nuBrowseFixed', saved: N.lookup ? null : brCookie});
		if (BR.ctl && BR.off) BR.ctl.off();
		return BR.ctl;
	}
	// the dragged widths also in a session cookie (0.8.933): the server draws the list with them, so a new page does not show the stored
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
	$(brRestore);                                                       // dragged widths before the first paint (0.8.931), again after nuOnLoad
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
		if (N.rights && N.rights.edit === false && !N.form.redirect) return;   // access level "browse only" (0.8.461): rows open nothing
		// nuBuilder Forte's "Target" of the list (0.8.820): 1 current breadcrumb (replaces the list), 2 new tab, 3 popup; 0 = as before
		var tg = N.iframe || newTab ? '0' : String(N.form.target || '0');
		if (tg === '3' && g('nuPopupModal')) { nuPopup(N.form.id, pk); return; }
		if (tg === '2') newTab = true;
		go(function () { return formUrl(N.form.id, pk, N.iframe ? {iframe: '1'} : (!newTab && tg !== '1' && N.bt && N.bt.child ? {bt: N.bt.child} : {})); },
			newTab ? 'blank' : (tg === '1' ? function (url) { window.location.replace(url); } : null));
	}

	$(document).on('click', 'tr.nuBrowseRow', function (e) {
		// the form takes over row clicks – with the clicked cell as element, as nuBuilder (not an element inside it, 0.8.468)
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
		$(document).on('change', '#nuRowSelAll', function () { var on = this.checked; document.querySelectorAll('#nuRECORD tr.nuBrowseRow .nuRowSel').forEach(function (b) { b.checked = on; }); selTotals(); });
		$(document).on('click', '#nuSelClear', function () { document.querySelectorAll('#nuRECORD .nuRowSel').forEach(function (b) { b.checked = false; }); selTotals(); });
		N.selectedTotals = selTotals;
	})();
	$(document).on('keydown', 'tr.nuBrowseRow', function (e) { if (e.key === 'Enter' && !e.target.closest('.nuRowDel, .nuCellAct')) openRow(this, e.ctrlKey || e.metaKey); });
	// row details (0.8.1027, core/form/nurowdetails.php): the ▸ of a row folds a strip open below it – the row's hidden (width 0) columns
	// and cut texts (read from the row itself) and the related list of the record (formapi action "details", loaded once per row)
	function detClose(tr) {
		var n = tr.nextElementSibling;
		if (n && n.classList.contains('nuDetRow')) n.remove();
		var b = tr.querySelector('.nuDetBtn');
		if (b) { b.setAttribute('aria-expanded', 'false'); b.title = M.rd_show; b.setAttribute('aria-label', M.rd_show); }
		tr.classList.remove('nuDetOpen');
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
	function detRelated(box, tr, res) {
		box.textContent = '';
		if (!res || !res.ok) { box.textContent = M.rd_error; return; }
		if (!res.title) { box.remove(); return; }
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
	function detOpen(tr) {
		var D = (N.browse || {}).details;
		if (!D || tr.classList.contains('nuDetOpen')) return;
		tr.classList.add('nuDetOpen');
		var b = tr.querySelector('.nuDetBtn');
		if (b) { b.setAttribute('aria-expanded', 'true'); b.title = M.rd_hide; b.setAttribute('aria-label', M.rd_hide); }
		var row = document.createElement('tr'), td = document.createElement('td'), body = document.createElement('div');
		row.className = 'nuDetRow';
		td.className = 'nuDetTd';
		td.colSpan = tr.children.length;
		body.className = 'nuDetBody';
		td.appendChild(body); row.appendChild(td);
		tr.parentNode.insertBefore(row, tr.nextSibling);
		var cols = D.cols ? detCols(tr) : null;
		if (cols) body.appendChild(cols);
		var pk = tr.getAttribute('data-nu-primary-key') || '';
		if (D.sub) {
			var box = document.createElement('div');
			box.className = 'nuDetRel';
			box.textContent = M.rd_loading;
			body.appendChild(box);
			var done = function (res) { tr._nuDet = res; detRelated(box, tr, res); if (!body.children.length) body.textContent = M.rd_empty; };
			if (tr._nuDet) done(tr._nuDet);
			else call('details', {record_id: pk}).then(done, function () { box.textContent = M.rd_error; });
		} else if (!cols) body.textContent = M.rd_empty;
		var open = document.createElement('button');
		open.type = 'button';
		open.className = 'btn btn-sm btn-outline-secondary nuDetOpenRec';
		open.innerHTML = '<i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>';
		open.appendChild(document.createTextNode(M.rd_open));
		open.addEventListener('click', function (e) { e.stopPropagation(); openRow(tr, e.ctrlKey || e.metaKey); });
		td.appendChild(open);
	}
	function detToggle(tr, want) {
		var on = tr.classList.contains('nuDetOpen');
		if (want === undefined) want = !on;
		if (want && !on) detOpen(tr); else if (!want && on) detClose(tr);
	}
	N.rowDetails = detToggle;
	$(document).on('click', '#nuBrowseTable .nuDetBtn', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var tr = this.closest('tr.nuBrowseRow');
		if (tr) detToggle(tr);
	});
	$(document).on('click', '#nuBrowseTable tr.nuDetRow', function (e) { e.stopPropagation(); });
	// click action of a cell (0.8.872, design mode → column → On click): instead of the row's edit form – another form, a list in the
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
	// delete button at the left of a browse row (Notes, 0.8.469): asks, deletes through the form API (rights, Before Delete, audit), reloads
	$(document).on('click', '#nuBrowseTable .nuRowDel', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var btn = this, pk = btn.getAttribute('data-nu-pk');
		var go = function () {
			btn.disabled = true;
			call('delete', {record_id: pk}).then(function (res) {
				if (!res.ok) { btn.disabled = false; applyResult(res); return; }
				try { sessionStorage.setItem('nuNextToast', JSON.stringify([M.deleted || 'Record deleted.', 'success'])); } catch (x) { /* ignore */ }
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

	// =================================================================== jQuery plugins (nuBuilder nucommon.js: $('#id').nuHide() …)
	// Each one calls the global function with the element id, as in nuBuilder.
	if (window.jQuery) jQuery.fn.extend({
		nuEnable: function (enable) {
			return this.each(function () {
				nuEnable(this.id, enable);
			});
		},
		nuReadonly: function (readonly) {
			return this.each(function () {
				nuReadonly(this.id, readonly);
			});
		},
		nuDisable: function () {
			return this.each(function () {
				nuDisable(this.id);
			});
		},
		nuShow: function (visible, openTab) {
			return this.each(function () {
				nuShow(this.id, visible, openTab);
			});
		},
		nuHide: function () {
			return this.each(function () {
				nuHide(this.id);
			});
		},
		nuRemove: function () {
			return this.each(function () {
				nuRemove(this.id);
			});
		},
		nuSelectRemoveEmpty: function (setIndex) {
			return this.each(function () {
				nuSelectRemoveEmpty(this.id, setIndex);
			});
		},
		nuGetValue: function (method) {
			return nuGetValue(this.attr('id'), method);
		},
		nuSetValue: function (v, method, change) {
			return this.each(function () {
				return nuSetValue(this.id, v, method, change);
			});
		},
		nuGetText: function () {
			return nuGetValue(this.attr('id'), 'text');
		},
		nuSetText: function (v) {
			return this.each(function () {
				return nuSetValue(this.id, v, 'text');
			});
		},
		nuGetHTML: function () {
			return nuGetValue(this.attr('id'), 'html');
		},
		nuTranslate: function (method) {
			return this.each(function () {
				nuSetValue(this.id, nuTranslate(nuGetValue(this.id, method)), method);
			});
		},
		nuRemoveFormatting: function () {
			return nuFORM.removeFormatting($(this).val(), $(this).attr('data-nu-format'));
		},
		nuIsVisible: function () {
			return nuIsVisible(this.attr('id'));
		},
		nuIsHidden: function () {
			return nuIsHidden(this.attr('id'));
		},
		nuIsDisabled: function () {
			return nuIsDisabled(this.attr('id'));
		},
		nuIsEnabled: function () {
			return nuIsEnabled(this.attr('id'));
		},
		nuSetFocus: function () {
			return nuSetFocus(this.attr('id'));
		},
		nuSetPlaceholder: function (placeholder, translate) {
			return this.each(function () {
				return nuSetPlaceholder(this.id, placeholder, translate);
			});
		},
		nuSetLabelText: function (str, translate) {
			return this.each(function () {
				nuSetLabelText(this.id, str, translate);
			});
		},
		nuTogglePassword: function (show) {
			return this.each(function () {
				var $input = $(this);
				var type = $input.attr('type') === 'password' ? 'text' : 'password';
				if (show !== undefined) {
					type = show ? 'text' : 'password';
				}
				$input.attr('type', type);
			});
		}
	});

	// =================================================================== label menu (globeadmin): quick changes of an object
	// Right-click on a label or button of the edit form (0.8.562): Object id (click = copy) · Properties… · Rename… · Actions (Delete, Move to tab) · Access · Align · Validation.
	// Changes are saved through the builder API and the form is reloaded.
	(function () {
		var OM = N.objmenu;
		if (!OM || (N.mode !== 'edit' && N.mode !== 'browse')) return;   // browse (0.8.929): the column title menu
		var T = OM.m, menu = null;

		function builderCall(action, data) {
			return fetch(N.base + '?p=builderapi', {
				method: 'POST', credentials: 'same-origin',
				headers: {'Content-Type': 'application/json', 'X-CSRF-Token': N.csrf, 'Accept': 'application/json'},
				// in a draft preview the label menu changes the draft (0.8.386); a live change while a draft is open is confirmed first
				body: JSON.stringify($.extend({action: action, form_id: N.form.id}, N.draft ? {draft_id: N.draft.id, rev: N.draft.rev} : {}, data))
			}).then(function (r) { return r.json().catch(function () { return {ok: false, error: 'HTTP ' + r.status}; }); })
				.then(function (j) {
					if (!j.ok && j.code === 'draft_open_confirm' && !data.live_ok && window.confirm(j.error)) return builderCall(action, $.extend({}, data, {live_ok: true}));
					if (!j.ok && j.code === 'draft_rev') { window.location.reload(); }
					if (!j.ok) throw new Error(j.error || T.error);
					if (N.draft && j.draft) N.draft.rev = j.draft.rev;
					return j;
				});
		}
		function change(o, fields) {
			if (N.objMenuHook) { N.objMenuHook('save', o, fields); return; }      // design mode (0.8.664): its draft, its reload
			if (N.edited && !window.confirm(T.unsaved)) return;
			builderCall('object.save', {object_id: o.object_id, fields: fields}).then(function () { N.edited = false; window.location.reload(); })
				.catch(function (e) { nuMessage([esc(e.message)]); });
		}
		function close() {
			if (menu) {
				var c = menu.find('.nuOmGeo').data('nuOmGeoCommit');            // a changed position / size is not lost when the menu closes
				if (c) c();
				$(document).off('pointerup.nuOmGeo');
				menu.remove(); menu = null;
			}
		}

		function item(icon, text, onClick, opt) {
			opt = opt || {};
			var li = $('<li></li>');
			var b = $('<button type="button" class="dropdown-item d-flex align-items-center gap-2"></button>')
				.append($('<i class="bi"></i>').addClass(icon || 'bi-dot').css('visibility', icon ? '' : 'hidden'))
				.append($('<span class="me-auto"></span>').text(text));
			if (opt.current) b.addClass('fw-bold').attr('aria-current', 'true').append('<i class="bi bi-check2"></i>');
			if (opt.danger) b.addClass('text-danger');
			if (opt.sub) {
				b.addClass('nuOmHasSub').attr({'aria-haspopup': 'true', 'aria-expanded': 'false'}).append('<i class="bi bi-chevron-right small"></i>');
				var sub = $('<ul class="dropdown-menu nuOmSub"></ul>');
				opt.sub.forEach(function (x) { sub.append(x); });
				li.addClass('nuOmSubWrap').append(b, sub);
				b.on('click', function (e) {                          // touch / keyboard: open the submenu
					e.stopPropagation();
					var open = !li.hasClass('nuOmOpen');
					li.siblings().removeClass('nuOmOpen').find('> button').attr('aria-expanded', 'false');
					li.toggleClass('nuOmOpen', open); b.attr('aria-expanded', open ? 'true' : 'false');
					if (open) sub.find('button').first().trigger('focus');
				});
				return li;
			}
			b.on('click', function (e) { e.stopPropagation(); close(); onClick(); });
			return li.append(b);
		}
		function divider() { return $('<li><hr class="dropdown-divider"></li>'); }

		// Top / Left / Width / Height (0.8.921, as nuBuilder Forte's object menu): the form changes while typing or spinning; saved once – on
		// Enter / leaving the field, and for the spin arrows when the mouse button is released (not on every step) – without a reload
		function geoRows(id, o) {
			var wrap = document.getElementById('nuw_' + id);
			if (o.top === undefined || !wrap || !wrap.classList.contains('nuAbs')) return $();
			var el = document.getElementById(id);
			var saved = {top: o.top, left: o.left, width: o.width, height: o.height}, pending = null, down = false;
			var apply = function (k, v) {
				if (k === 'height') {
					wrap.style.height = v + 'px';
					if (el && el !== wrap && /^(TEXTAREA|SELECT|INPUT|BUTTON|IFRAME|IMG)$/.test(el.tagName)) el.style.height = v + 'px';
				} else wrap.style[k] = v + 'px';
			};
			var commit = function () {
				if (!pending) return;
				var f = pending; pending = null;
				var same = Object.keys(f).every(function (k) { return f[k] === saved[k]; });
				if (same) return;
				if (N.objMenuHook) { N.objMenuHook('save', o, {sob_all_top: f.top, sob_all_left: f.left, sob_all_width: f.width, sob_all_height: f.height}); return; }
				builderCall('objects.geometry', {items: [$.extend({object_id: o.object_id}, f)]}).then(function () {
					Object.keys(f).forEach(function (k) { saved[k] = o[k] = f[k]; });   // no message: a series of clicks would show one per click
				}).catch(function (e) { nuMessage([esc(e.message)]); });
			};
			// several clicks on a spin arrow in a row: one save 700 ms after the last release
			var timer = null;
			var later = function () { clearTimeout(timer); timer = setTimeout(commit, 700); };
			var li = $('<li class="nuOmGeo"></li>');
			['top', 'left', 'width', 'height'].forEach(function (k) {
				var inp = $('<input type="number" class="form-control form-control-sm nuOmGeoInput" step="1">')
					.attr({min: k === 'top' || k === 'left' ? 0 : 10, 'data-geo': k, 'aria-label': T['geo_' + k]}).val(saved[k]);
				var row = $('<label class="nuOmGeoRow"></label>').append($('<span></span>').text(T['geo_' + k]), inp);
				inp.on('click mousedown keydown', function (e) { e.stopPropagation(); });
				inp.on('pointerdown', function () { down = true; });
				inp.on('input', function () {
					var v = parseInt(this.value, 10);
					if (isNaN(v)) return;
					v = Math.max(k === 'top' || k === 'left' ? 0 : 10, v);
					apply(k, v);
					pending = $.extend(pending || $.extend({}, saved), (function () { var x = {}; x[k] = v; return x; })());
				});
				inp.on('change', function () { if (!down) commit(); });          // typed + Enter / blur; spinning waits for the release
				inp.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
				li.append(row);
			});
			$(document).on('pointerup.nuOmGeo', function () { if (down) { down = false; later(); } });
			li.data('nuOmGeoCommit', function () { clearTimeout(timer); commit(); });
			return $().add(divider()).add(li);
		}

		// Button settings (0.8.1163): one dialog for a run object or an input button – Run (target, record, filter, method, open in),
		// Events (JavaScript per event), Label & look (label, icon), Access; one Save through the builder API (live or the open draft)
		var EVENTS = ['onclick', 'onchange', 'onblur', 'onfocus', 'oninput', 'onkeydown', 'onkeyup', 'ondblclick', 'onmouseover', 'onmouseout'];
		function isButton(o) { return o && (o.type === 'run' || (o.type === 'input' && o.input === 'button')); }
		function settings(id) {
			var o = N.objects[id];
			if (!isButton(o)) return;
			if (N.objMenuHook) { N.objMenuHook('properties', o); return; }
			$('#nuOmSetDlg').remove();
			var L = OM.run_lists || {forms: [], procs: [], reports: [], pivots: []};
			var r = o.run ? $.extend({}, o.run) : null;
			var dlg = $('<div class="modal fade nuOmSetDlg" id="nuOmSetDlg" tabindex="-1" aria-labelledby="nuOmSetTitle"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">'
				+ '<div class="modal-header py-2"><h2 class="modal-title fs-5 text-truncate" id="nuOmSetTitle"></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
				+ '<div class="modal-body pt-2"><ul class="nav nav-tabs mb-3" role="tablist"></ul><div class="nuOmSetPanes"></div></div>'
				+ '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"></button><button type="button" class="btn btn-primary" id="nuOmSetSave"></button></div>'
				+ '</div></div></div>').appendTo('body');
			dlg.find('.modal-title').append($('<i class="bi bi-gear me-2"></i>'), $('<code></code>').text(id));
			dlg.find('.btn-close').attr('aria-label', T.cancel);
			dlg.find('.modal-footer .btn-outline-secondary').text(T.cancel);
			dlg.find('#nuOmSetSave').text(T.save);
			var tabs = dlg.find('.nav-tabs'), panes = dlg.find('.nuOmSetPanes');
			function pane(key, title) {
				var b = $('<button type="button" class="nav-link" role="tab"></button>').attr({id: 'nuOmSetTab_' + key, 'data-pane': key}).text(title);
				tabs.append($('<li class="nav-item" role="presentation"></li>').append(b));
				var p = $('<div class="nuOmSetPane" role="tabpanel" hidden></div>').attr('id', 'nuOmSetPane_' + key);
				panes.append(p);
				b.on('click', function () {
					tabs.find('.nav-link').removeClass('active').attr('aria-selected', 'false');
					b.addClass('active').attr('aria-selected', 'true');
					panes.children().prop('hidden', true); p.prop('hidden', false);
					try { localStorage.setItem('nunext-om-set-tab', key); } catch (e) { /* none */ }
				});
				return p;
			}
			function row(label, ctl, tip) {
				var lb = $('<label class="form-label mb-1"></label>').text(label);
				var d = $('<div class="mb-3"></div>').append(lb);
				if (tip) d.find('label').after($('<span class="nuInfoTip ms-1" tabindex="0" role="img"><i class="bi bi-info-circle"></i></span>').attr({title: tip, 'aria-label': tip}));
				return d.append(ctl);
			}
			function select(id2, opts, val) {
				var sel = $('<select class="form-select"></select>').attr('id', id2);
				opts.forEach(function (x) { sel.append($('<option></option>').val(x[0]).text(x[1]).prop('selected', String(x[0]) === String(val))); });
				return sel;
			}

			// --- Run
			var runFields = null;
			if (r) {
				var pRun = pane('run', T.set_run);
				var typeSel = select('nuOmSetType', ['F', 'P', 'R', 'U', 'V'].map(function (k) { return [k, T.r_types[k]]; }), r.type);
				var tgtWrap = $('<div id="nuOmSetTargetWrap"></div>');
				var urlIn = $('<input type="text" class="form-control font-monospace" id="nuOmSetUrl" placeholder="https://">').val(r.url || '');
				var recIn = $('<input type="text" class="form-control font-monospace" id="nuOmSetRecord">').val(r.record);
				var fltIn = $('<input type="text" class="form-control font-monospace" id="nuOmSetFilter">').val(r.filter);
				var methSel = select('nuOmSetMethod', [['b', T.r_b], ['i', T.r_i]], r.method);
				var openWrap = $('<div id="nuOmSetOpenWrap"></div>');
				var link = $('<a class="small" target="_blank" rel="noopener" id="nuOmSetOpenTarget"></a>').append('<i class="bi bi-box-arrow-up-right me-1"></i>', document.createTextNode(T.open_target));
				var recRow = row(T.r_record, recIn, T.r_record_tip), fltRow = row(T.r_filter, fltIn, T.r_filter_tip), urlRow = row(T.r_url, urlIn);
				pRun.append(row(T.set_run, typeSel), tgtWrap, urlRow, recRow, fltRow, row(T.r_method, methSel), openWrap, link);
				var ts = null, tgtSel = null;
				var drawTarget = function (keep) {
					var k = typeSel.val();
					if (ts) { ts.destroy(); ts = null; }
					tgtWrap.empty(); tgtSel = null;
					var list = k === 'F' ? L.forms : k === 'P' ? L.procs : k === 'R' ? L.reports : k === 'V' ? L.pivots : null;
					if (list) {
						var cur = keep ? (k === 'V' ? r.pivot : r.target) : '';
						tgtSel = select('nuOmSetTarget', [['', T.please_select]].concat(list.map(function (x) { return [x[0], x[1]]; })), cur);
						tgtWrap.append(row(k === 'F' ? T.r_form : k === 'P' ? T.r_proc : k === 'R' ? T.r_report : T.r_pivot, tgtSel));
						if (window.TomSelect) ts = new TomSelect(tgtSel[0], {maxOptions: null, allowEmptyOption: true, create: false, dropdownParent: 'body', plugins: ['dropdown_input'],
							onItemAdd: function () { this.close(); this.blur(); }});
						tgtSel.on('change', drawLink);
					}
					urlRow.prop('hidden', k !== 'U'); recRow.prop('hidden', k !== 'F'); fltRow.prop('hidden', k !== 'F');
					drawOpen();
					drawLink();
				};
				var drawOpen = function () {
					var k = typeSel.val();
					openWrap.empty();
					if (methSel.val() === 'i' || k === 'P' || k === 'R') return;
					var opts = k === 'F' ? [0, 1, 2, 3].map(function (i) { return [String(i), T.r_targets[i]]; }) : [['2', T.r_targets[2]], ['3', T.r_url_popup], ['0', T.r_url_same]];
					var cur = String(r.open);
					if (k !== 'F' && cur === '1') cur = '0';
					openWrap.append(row(T.r_open, select('nuOmSetOpen', opts, opts.some(function (x) { return x[0] === cur; }) ? cur : opts[0][0])));
				};
				var drawLink = function () {
					var k = typeSel.val(), v = tgtSel ? tgtSel.val() : '';
					var href = !v ? '' : k === 'F' ? '?p=form&f=' + encodeURIComponent(v) : k === 'P' ? '?p=procedures&id=' + encodeURIComponent(v)
						: k === 'R' ? '?p=reports&id=' + encodeURIComponent(v) : k === 'V' ? '?p=form&f=' + encodeURIComponent(v.split('|')[0]) : '';
					link.prop('hidden', !href).attr('href', href ? N.base + href : null);
				};
				typeSel.on('change', function () { drawTarget(typeSel.val() === r.type); });
				methSel.on('change', drawOpen);
				drawTarget(true);
				runFields = function () {
					var k = typeSel.val(), v = tgtSel ? tgtSel.val() : '', op = $('#nuOmSetOpen').val();
					var f = {sob_run_method: methSel.val()};
					if (op !== undefined) f.sob_run_target = op;
					if (k === 'V') return v ? $.extend(f, {nunext_run_pivot: v, nunext_run_url: null}) : null;
					if (k === 'U') return urlIn.val().trim() ? $.extend(f, {nunext_run_url: urlIn.val().trim(), nunext_run_pivot: null}) : null;
					if (!v) return null;
					return $.extend(f, {sob_run_type: k, sob_run_zzzzsys_form_id: v, sob_run_id: k === 'F' ? recIn.val() : '', sob_run_filter: k === 'F' ? fltIn.val() : '',
						nunext_run_url: null, nunext_run_pivot: null});
				};
			}

			// --- Events
			var pEv = pane('events', T.set_events + ((o.js || []).length ? ' (' + o.js.length + ')' : ''));
			var evRows = $('<div class="nuOmSetEvRows d-grid gap-2"></div>');
			var addEv = function (ev) {
				var sel = select('', EVENTS.concat(ev.event && EVENTS.indexOf(ev.event) < 0 ? [ev.event] : []).map(function (x) { return [x, x]; }), ev.event || 'onclick')
					.addClass('form-select-sm nuOmSetEvName').attr('aria-label', T.event).removeAttr('id');
				var ta = $('<textarea class="form-control font-monospace nuOmSetEvJs" rows="3" spellcheck="false" aria-label="JavaScript"></textarea>').val(ev.js || '');
				var del = $('<button type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>').attr({title: T.delete, 'aria-label': T.delete});
				var rw = $('<div class="nuOmSetEv"></div>').append($('<div class="d-flex gap-2 mb-1"></div>').append(sel.css('max-width', '14rem'), del.addClass('ms-auto')), ta);
				del.on('click', function () { rw.remove(); });
				evRows.append(rw);
			};
			(o.js || []).forEach(addEv);
			pEv.append(evRows, $('<button type="button" class="btn btn-sm btn-outline-primary mt-2" id="nuOmSetEvAdd"><i class="bi bi-plus-lg me-1"></i></button>')
				.append(document.createTextNode(T.add_event)).on('click', function () { addEv({}); evRows.find('textarea').last().trigger('focus'); }));

			// --- Label & look
			var pLook = pane('look', T.set_look);
			var lblIn = $('<input type="text" class="form-control" id="nuOmSetLabel">').val(o.label_raw !== undefined ? o.label_raw : o.label);
			var icoIn = $('<input type="text" class="form-control font-monospace" id="nuOmSetIcon" placeholder="bi-house">').val(o.icon || '');
			pLook.append(row(T.label, lblIn), row(T.icon, icoIn, T.icon_tip));

			// --- Access
			var pAcc = pane('access', T.access);
			var accSel = select('nuOmSetAccess', T.access_v.map(function (txt, i) { return [String(i), txt]; }), String(o.access || '0'));
			pAcc.append(row(T.access, accSel));

			var last = '';
			try { last = localStorage.getItem('nunext-om-set-tab') || ''; } catch (e) { /* none */ }
			(tabs.find('[data-pane="' + last + '"]').length ? tabs.find('[data-pane="' + last + '"]') : tabs.find('.nav-link').first()).trigger('click');

			dlg.find('#nuOmSetSave').on('click', function () {
				var f = {sob_all_label: lblIn.val(), sob_input_icon: icoIn.val().trim(), sob_all_access: accSel.val()};
				if (runFields) {
					var rf = runFields();
					if (!rf) { tabs.find('[data-pane=run]').trigger('click'); (tgtSel || urlIn).trigger('focus'); return; }
					$.extend(f, rf);
				}
				var events = evRows.children().map(function () {
					return {event: $(this).find('.nuOmSetEvName').val(), js: $(this).find('.nuOmSetEvJs').val()};
				}).get().filter(function (x) { return x.js.trim() !== ''; });
				if (N.edited && !window.confirm(T.unsaved)) return;
				var b = $(this).prop('disabled', true);
				builderCall('object.save', {object_id: o.object_id, fields: f, events: events})
					.then(function () { N.edited = false; window.location.reload(); })
					.catch(function (e) { b.prop('disabled', false); nuMessage([esc(e.message)]); });
			});
			dlg.on('hidden.bs.modal', function () { if (ts) ts.destroy(); dlg.remove(); });
			nuBs.modal(dlg[0]).show();
		}
		N.objButtonSettings = settings;
		// Alt+click on a button opens its settings (not in design mode, which selects objects on click)
		document.addEventListener('click', function (e) {
			if (!e.altKey || N.objMenuHook || N.mode !== 'edit') return;
			var b = e.target.closest && e.target.closest('#nuEditForm button.nuRunButton[id], #nuEditForm button.nuButton[id], #nuEditForm input[type=button][id]');
			if (!b || !isButton(N.objects[b.id])) return;
			e.preventDefault(); e.stopImmediatePropagation();
			settings(b.id);
		}, true);

		function open(id, x, y) {
			close();
			var o = N.objects[id];
			if (!o) return;
			var head = $('<li></li>').append($('<button type="button" class="dropdown-item nuOmHead"></button>')
				.append($('<span class="text-body-secondary"></span>').text(T.object + ': ')).append($('<b></b>').text(id))
				.attr('title', T.copied.replace('%s', id))
				.on('click', function (e) {
					e.stopPropagation(); close();
					var done = function () { nuToast(T.copied.replace('%s', id), 'success'); };
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(id).then(done, function () { window.prompt(T.object, id); });
					else window.prompt(T.object, id);               // http: no clipboard API – the id is selected in the prompt
				}));
			var tabs = (OM.tabs || []).filter(function (t) { return t[0] !== o.tab; }).map(function (t) {
				return item('bi-folder2', t[1] || t[0], function () { change(o, {sob_all_zzzzsys_tab_id: t[0]}); });
			});
			// Clone (0.8.927): a copy of the object (id <id>_2 …, below the original, same events) – saved at once, the form reloads
			var actions = [item('bi-copy', T.clone, function () {
				if (N.objMenuHook) { N.objMenuHook('clone', o); return; }
				if (N.edited && !window.confirm(T.unsaved)) return;
				builderCall('object.clone', {object_id: o.object_id}).then(function () { N.edited = false; window.location.reload(); })
					.catch(function (e) { nuMessage([esc(e.message)]); });
			}), item('bi-trash', T.delete, function () {
				if (N.objMenuHook) { N.objMenuHook('delete', o); return; }
				if (!window.confirm(T.delete_confirm.replace('%s', id))) return;
				if (N.edited && !window.confirm(T.unsaved)) return;
				builderCall('object.delete', {object_id: o.object_id}).then(function () { N.edited = false; window.location.reload(); })
					.catch(function (e) { nuMessage([esc(e.message)]); });
			}, {danger: true})];
			if (tabs.length) actions.push(item('bi-folder-symlink', T.move, null, {sub: tabs}));
			var accIcons = ['bi-pencil', 'bi-lock', 'bi-eye-slash', 'bi-person-slash', 'bi-person-lock'];
			var access = T.access_v.map(function (txt, i) { return item(accIcons[i], txt, function () { change(o, {sob_all_access: String(i)}); }, {current: String(o.access || '0') === String(i)}); });
			var align = [['left', 'bi-text-left'], ['center', 'bi-text-center'], ['right', 'bi-text-right']].map(function (a) {
				return item(a[1], T[a[0]], function () { change(o, {sob_all_align: a[0]}); }, {current: (o.align || 'left') === a[0]});
			});
			var valIcons = ['bi-globe', 'bi-input-cursor', 'bi-key', 'bi-star-fill'];
			var validate = T.validate_v.map(function (txt, i) { return item(valIcons[i], txt, function () { change(o, {sob_all_validate: String(i)}); }, {current: String(o.validate || '0') === String(i)}); });

			menu = $('<ul class="dropdown-menu show nuObjMenu shadow" role="menu"></ul>').append(
				head, divider(),
				isButton(o) ? (function () { var li = item('bi-gear', T.settings, function () { settings(id); }); li.find('button').attr({title: T.settings_tip, id: 'nuOmSettings'}); return li; })() : $(),
				item('bi-sliders', T.properties, function () { if (N.objMenuHook) { N.objMenuHook('properties', o); return; } window.open(N.base + '?p=builder&f=' + encodeURIComponent(N.form.id) + '&obj=' + encodeURIComponent(o.object_id), '_blank'); }),
				item('bi-input-cursor-text', T.rename, function () {
					var cur = labelText(id) || o.label || '';            // words / images have no separate label (0.8.1082)
					var v = window.prompt(T.rename_prompt.replace('%s', id), cur);
					if (v !== null && v.trim() !== '' && v !== cur) change(o, {sob_all_label: v.trim()});
				}),
				divider(), item('bi-lightning', T.actions, null, {sub: actions}),
				divider(), item('bi-shield-lock', T.access, null, {sub: access}), item('bi-text-left', T.align, null, {sub: align}),
				item('bi-check2-square', T.validation, null, {sub: validate}),
				geoRows(id, o)
			).appendTo('body');
			placeMenu(x, y);
		}
		function placeMenu(x, y) {
			// inside the window
			// phones (0.8.1160): the long press selected a word – the browser's search sheet then covers the bottom; drop the selection
			// and fit the menu into the visible part of the window (visualViewport), not the layout height
			try { var sel = window.getSelection && window.getSelection(); if (sel && !sel.isCollapsed) sel.removeAllRanges(); } catch (e) { /* none */ }
			var VV = window.visualViewport;
			var w = menu.outerWidth(), h = menu.outerHeight(), vw = document.documentElement.clientWidth,
				vh = VV ? Math.min(document.documentElement.clientHeight, VV.height + VV.offsetTop) : document.documentElement.clientHeight;
			var left = Math.max(4, Math.min(x, vw - w - 4)), SUBW = 220;
			// submenus beside the menu: right, or left near the right edge; no room on either side (phones) → they unfold inside the menu
			var inline = left + w + SUBW > vw && left - SUBW < 0;
			menu.toggleClass('nuOmInline', inline).toggleClass('nuOmFlip', !inline && left + w + SUBW > vw);
			function place() {                                        // keep the whole menu inside the window (also after a submenu unfolds)
				var hh = menu.outerHeight();
				menu.css({top: Math.max(4 + (VV ? VV.offsetTop : 0), Math.min(parseFloat(menu.css('top')) || y, vh - hh - 4)) + 'px'});
			}
			menu.css({position: 'fixed', left: left + 'px', top: Math.max(4 + (VV ? VV.offsetTop : 0), Math.min(y, vh - h - 4)) + 'px', zIndex: 1070, maxHeight: (vh - 8 - (VV ? VV.offsetTop : 0)) + 'px', overflowY: inline ? 'auto' : ''});
			// a submenu that would leave the window at the bottom opens upwards from its row
			menu.on('mouseenter focusin click', '.nuOmSubWrap', function () {
				if (inline) { place(); return; }
				var sub = $(this).children('.nuOmSub').removeClass('nuOmUp');
				var r = sub[0].getBoundingClientRect();
				if (r.height && r.bottom > document.documentElement.clientHeight - 4) sub.addClass('nuOmUp');
			});
			menu.find('button').first().trigger('focus');
		}

		// right-click on a tab (0.8.919): tab id (click = copy) · Rename… · Visibility · Move left / right · Delete · Edit in design mode
		// – the same changes as the tab menu of design mode, saved through the builder API (tabs.save = the whole list) and reloaded
		function tabRows() { return (OM.tabs || []).map(function (t) { return {id: t[0], title: t[2] !== undefined ? t[2] : t[1], access: String(t[3] || '0')}; }); }
		function saveTabs(rows) {
			if (N.edited && !window.confirm(T.unsaved)) return;
			builderCall('tabs.save', {tabs: rows}).then(function () { N.edited = false; window.location.reload(); })
				.catch(function (e) { nuMessage([esc(e.message)]); });
		}
		function openTab(tabId, x, y) {
			close();
			var rows = tabRows(), i = rows.map(function (r) { return r.id; }).indexOf(tabId);
			if (i < 0) return;
			var row = rows[i], title = (OM.tabs[i] || [])[1] || row.id, acc = row.access;
			var setAcc = function (a) { saveTabs(tabRows().map(function (r) { if (r.id === tabId) r.access = a; return r; })); };
			var move = function (d) { var r = tabRows(), x0 = r.splice(i, 1)[0]; r.splice(i + d, 0, x0); saveTabs(r); };
			var head = $('<li></li>').append($('<button type="button" class="dropdown-item nuOmHead"></button>')
				.append($('<span class="text-body-secondary"></span>').text(T.tab + ': ')).append($('<b></b>').text(title))
				.attr('title', T.copied.replace('%s', tabId))
				.on('click', function (e) {
					e.stopPropagation(); close();
					var done = function () { nuToast(T.copied.replace('%s', tabId), 'success'); };
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(tabId).then(done, function () { window.prompt(T.tab, tabId); });
					else window.prompt(T.tab, tabId);
				}));
			var vis = [['0', 'bi-eye', T.tab_visible], ['2', 'bi-eye-slash', T.tab_hide], ['3', 'bi-person-lock', T.tab_hide_users]].map(function (v) {
				return item(v[1], v[2], function () { setAcc(v[0]); }, {current: acc === v[0]});
			});
			var items = [head, divider(),
				item('bi-input-cursor-text', T.tab_rename, function () {
					var v = window.prompt(T.tab_rename_prompt.replace('%s', title), title);
					if (v !== null && v.trim() !== '' && v.trim() !== title) saveTabs(tabRows().map(function (r) { if (r.id === tabId) r.title = v.trim(); return r; }));
				}),
				item('bi-eye', T.tab_access, null, {sub: vis})];
			if (i > 0) items.push(item('bi-arrow-left', T.tab_left, function () { move(-1); }));
			if (i < rows.length - 1) items.push(item('bi-arrow-right', T.tab_right, function () { move(1); }));
			if (rows.length > 1) {
				items.push(divider(), item('bi-trash', T.tab_delete, function () {
					var n = Object.keys(N.objects || {}).filter(function (k) { return N.objects[k] && N.objects[k].tab === tabId; }).length;
					var other = (OM.tabs[i === 0 ? 1 : 0] || [])[1] || '';
					var msg = n ? T.tab_delete_confirm_n.replace('%s', title).replace('%s', String(n)).replace('%s', other) : T.tab_delete_confirm.replace('%s', title);
					var go = function () { saveTabs(tabRows().filter(function (r) { return r.id !== tabId; })); };
					if (typeof window.nuConfirmAsk === 'function') window.nuConfirmAsk(msg, go, T.delete, 'btn btn-danger', {cancelFirst: true});
					else if (window.confirm(msg)) go();
				}, {danger: true}));
			}
			if (OM.design) items.push(divider(), item('bi-pencil-square', T.tab_design, function () { window.location.href = OM.design; }));
			menu = $('<ul class="dropdown-menu show nuObjMenu nuTabMenu shadow" role="menu"></ul>').append(items).appendTo('body');
			placeMenu(x, y);
		}
		$(document).on('contextmenu', '#nuTabHolder .nuTab[data-nu-tab-id]', function (e) {
			if (e.ctrlKey || N.objMenuHook) return;                              // Ctrl: the browser's menu · design mode has its own tab menu
			e.preventDefault();
			e.stopPropagation();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var r = this.getBoundingClientRect(); x = r.left + 8; y = r.bottom; }
			openTab(this.getAttribute('data-nu-tab-id'), x, y);
		});

		// right-click on a column title of the list (0.8.929, as design mode's column menu): title + id (click = copy) · Properties… (design
		// mode with the column selected) · Rename… · Align · Width · Filter · Move left / right · Hide · Delete – saved with browse.save
		// (the whole list of columns) and reloaded
		function colRows() { return (OM.cols || []).map(function (c) { return {id: c[0], title: c[1], display: c[2], align: c[3], format: c[4], width: c[5], filter: !!c[6]}; }); }
		function saveCols(rows) {
			builderCall('browse.save', {columns: rows}).then(function () { window.location.reload(); })
				.catch(function (e) { nuMessage([esc(e.message)]); });
		}
		function openCol(th, x, y) {
			close();
			var bid = th.getAttribute('data-nu-browse-id'), rows = colRows(), i = rows.map(function (r) { return r.id; }).indexOf(bid);
			if (i < 0) return;
			var c = rows[i], oid = th.id || ('nuBrowseTitle' + th.getAttribute('data-nu-col'));
			var set = function (part) { saveCols(colRows().map(function (r) { return r.id === bid ? $.extend(r, part) : r; })); };
			var move = function (d) { var r = colRows(), x0 = r.splice(i, 1)[0]; r.splice(i + d, 0, x0); saveCols(r); };
			var name = $('<div>').html(c.title).text() || c.display;
			var head = $('<li></li>').append($('<button type="button" class="dropdown-item nuOmHead"></button>')
				.append($('<span class="fw-semibold me-1"></span>').text(name)).append($('<small class="text-body-secondary"></small>').text(oid))
				.attr('title', T.copied.replace('%s', oid))
				.on('click', function (e) {
					e.stopPropagation(); close();
					var done = function () { nuToast(T.copied.replace('%s', oid), 'success'); };
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(oid).then(done, function () { window.prompt(T.object, oid); });
					else window.prompt(T.object, oid);
				}));
			var width = $('<input type="number" min="0" step="1" class="form-control form-control-sm nuOmGeoInput nuOmColWidth">').val(String(c.width)).attr('aria-label', T.col_width)
				.on('click', function (e) { e.stopPropagation(); })
				.on('keydown', function (e) {
					e.stopPropagation();
					if (e.key === 'Enter') { e.preventDefault(); close(); }
					if (e.key === 'Escape') { e.preventDefault(); width.val(String(c.width)); close(); }
				});
			var commitWidth = function () { var v = Math.max(0, parseInt(width.val(), 10) || 0); if (v !== c.width) set({width: v}); };   // Enter, or the menu closes
			var align = [['l', 'bi-text-left', T.left], ['r', 'bi-text-right', T.right], ['c', 'bi-text-center', T.center]].map(function (a) {
				return item(a[1], a[2], function () { if (c.align !== a[0]) set({align: a[0]}); }, {current: c.align === a[0]});
			});
			var items = [head, divider()];
			if (OM.design) items.push(item('bi-sliders', T.properties, function () {
				try { window.sessionStorage.setItem('nunext-design:' + N.form.id, JSON.stringify({col: bid})); } catch (e) { /* no storage */ }
				window.location.href = OM.design;
			}));
			items.push(item('bi-input-cursor-text', T.rename, function () {
				var v = window.prompt(T.rename_prompt.replace('%s', oid), c.title);
				if (v !== null && v.trim() !== '' && v !== c.title) set({title: v.trim()});
			}), divider(), item('bi-text-left', T.align, null, {sub: align}),
			$('<li class="nuOmGeo"></li>').data('nuOmGeoCommit', commitWidth).append($('<label class="nuOmGeoRow"></label>').append($('<span class="d-flex align-items-center gap-2"><i class="bi bi-arrows"></i></span>').append($('<span></span>').text(T.col_width)), width)),
			item('bi-funnel', T.col_filter, function () { set({filter: !c.filter}); }, {current: c.filter}), divider());
			if (i > 0) items.push(item('bi-arrow-left', T.col_left, function () { move(-1); }));
			if (i < rows.length - 1) items.push(item('bi-arrow-right', T.col_right, function () { move(1); }));
			items.push(item('bi-eye-slash', T.col_hide, function () { set({width: 0}); }), divider(), item('bi-trash', T.delete, function () {
				var go = function () { saveCols(colRows().filter(function (r) { return r.id !== bid; })); };
				var msg = T.col_delete_confirm.replace('%s', name);
				if (typeof window.nuConfirmAsk === 'function') window.nuConfirmAsk(msg, go, T.delete, 'btn btn-danger', {cancelFirst: true});
				else if (window.confirm(msg)) go();
			}, {danger: true}));
			menu = $('<ul class="dropdown-menu show nuObjMenu nuColMenu shadow" role="menu"></ul>').append(items).appendTo('body');
			placeMenu(x, y);
		}
		$(document).on('contextmenu', '#nuBrowseTable th.nuBrowseTitle[data-nu-browse-id]', function (e) {
			if (e.ctrlKey || N.mode !== 'browse' || $('#nuBrowseTable').hasClass('nuDsBrowse')) return;   // Ctrl: the browser's menu · design mode has its own
			e.preventDefault();
			e.stopPropagation();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var r = this.getBoundingClientRect(); x = r.left + 8; y = r.bottom; }
			openCol(this, x, y);
		});

		// right-click as in nuBuilder 4.x (0.8.562 – before: a left click, which kept labels from focusing their field): labels, content
		// box titles and buttons (input buttons, run buttons); Ctrl + right-click keeps the browser's own menu (inspect); the context-menu
		// key / Shift+F10 opens it at the element
		// 0.8.569: also the title an HTML object draws itself (a framed box from the form's CSS: .title, h1–h6, legend, .card-header)
		var HTML_TITLES = ['.title', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'legend', '.card-header'].map(function (x) { return '#nuEditForm .nuHtml[id] ' + x; }).join(', ');
		// 0.8.1082: also words and images (as nuBuilder Forte)
		$(document).on('contextmenu', '#nuEditForm label[id^="label_"], #nuEditForm .nuContentBoxTitle[id^="label_"], #nuEditForm button.nuButton[id], #nuEditForm button.nuRunButton[id], #nuEditForm .nuWord[id], #nuEditForm img.nuImage[id], ' + HTML_TITLES, function (e) {
			if (e.ctrlKey) return;
			var html = $(this).closest('.nuHtml[id]');
			var id = html.length && !$(this).is('label, button') ? html.attr('id') : (/^label_/.test(this.id) && !$(this).is('button') ? this.id.substring(6) : this.id);
			if (!N.objects[id]) return;                              // subform columns / rows and labels without object: the browser's menu
			e.preventDefault();
			e.stopPropagation();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var r = this.getBoundingClientRect(); x = r.left + 8; y = r.bottom; }   // from the keyboard
			open(id, x, y);
		});
		// sub-form column titles (0.8.1082, as nuBuilder Forte): the column is an object of the sub-form's own form – its id, Properties
		// (design mode of that form with the object chosen) and the sub-form's form in design mode
		function openSfCol(th, x, y) {
			close();
			var id = th.getAttribute('data-nu-field'), oid = th.getAttribute('data-nu-obj'), f = th.getAttribute('data-nu-sf-form');
			var url = function (obj) { return N.base + '?p=form&f=' + encodeURIComponent(f) + '&r=-1&design=1' + (obj ? '&dsobj=' + encodeURIComponent(obj) : ''); };
			var head = $('<li></li>').append($('<button type="button" class="dropdown-item nuOmHead"></button>')
				.append($('<span class="text-body-secondary"></span>').text(T.object + ': ')).append($('<b></b>').text(id))
				.attr('title', T.copied.replace('%s', id))
				.on('click', function (e) {
					e.stopPropagation(); close();
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(id).then(function () { nuToast(T.copied.replace('%s', id), 'success'); });
					else window.prompt(T.object, id);
				}));
			menu = $('<ul class="dropdown-menu show nuObjMenu nuSfColMenu shadow" role="menu"></ul>').append(head, divider(),
				item('bi-sliders', T.properties, function () { window.open(url(oid), '_blank'); }),
				item('bi-ui-checks-grid', T.sf_form || T.properties, function () { window.open(url(''), '_blank'); })
			).appendTo('body');
			placeMenu(x, y);
		}
		$(document).on('contextmenu', '#nuEditForm th.nuSubformTitle[data-nu-obj][data-nu-sf-form]', function (e) {
			if (e.ctrlKey || N.objMenuHook || !OM.design) return;          // design mode: the form's own tools · no design right: the browser's menu
			e.preventDefault();
			e.stopPropagation();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var r = this.getBoundingClientRect(); x = r.left + 8; y = r.bottom; }
			openSfCol(this, x, y);
		});
		N.objMenuOpen = open;                                                // design mode opens it from the object's overlay (0.8.664)
		$(document).on('click', function (e) { if (menu && !$(e.target).closest('.nuObjMenu').length) close(); });
		// keys while the menu is open: on window in the capture phase – before Bootstrap's dropdown handler (capture on document),
		// which would look for a toggle button of our menu and fail
		window.addEventListener('keydown', function (e) {
			if (!menu || ['Escape', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].indexOf(e.key) === -1) return;
			if (e.key !== 'Escape' && document.activeElement && document.activeElement.classList.contains('nuOmGeoInput')) return;   // the number fields step
			e.stopPropagation();
			e.preventDefault();
			var act = document.activeElement, inMenu = menu[0].contains(act);
			var list = menu.find('button:visible').toArray(), i = list.indexOf(act);
			if (e.key === 'Escape') {
				var sub = inMenu ? $(act).closest('.nuOmSubWrap.nuOmOpen') : $();
				if (sub.length) { sub.removeClass('nuOmOpen'); sub.find('> button').attr('aria-expanded', 'false').trigger('focus'); } else close();
			} else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				if (list.length) list[i < 0 ? 0 : (i + (e.key === 'ArrowDown' ? 1 : list.length - 1)) % list.length].focus();
			} else if (e.key === 'ArrowRight' && $(act).hasClass('nuOmHasSub')) {
				$(act).trigger('click');
			} else if (e.key === 'ArrowLeft') {
				var w = $(act).closest('.nuOmSub').closest('.nuOmSubWrap');
				if (w.length) { w.removeClass('nuOmOpen'); w.find('> button').attr('aria-expanded', 'false').trigger('focus'); }
			}
		}, true);
		$(window).on('scroll resize blur', close);
		$('body').addClass('nuObjMenuOn');
	})();

	// =================================================================== reports (nuBuilder's nuRunReport …, PDF made by ?p=reportpdf)
	/** Prepares a run with this form's values (criteria) → {id: token, code}; rejects after showing the error. */
	function reportRun(code) {
		return call('report', {code: String(code || ''), values: N.mode === 'edit' ? mainValues() : {}}).then(function (res) {
			if (!res || !res.ok) { nuDisplayError(res || {errors: [{message: 'Report'}]}); throw new Error('report'); }
			return res;
		});
	}
	function reportUrl(id) { return N.base + '?p=reportpdf&i=' + encodeURIComponent(id); }
	function reportPost(data) {
		var fd = new FormData();
		Object.keys(data).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
		fd.append('_csrf', N.csrf);
		return fetch(N.base + '?p=reportpdf', {method: 'POST', credentials: 'same-origin', body: fd})
			.then(function (r) { return r.json().catch(function () { return {ok: false, errors: [{message: 'HTTP ' + r.status}]}; }); });
	}
	/** nuBuilder: nuRunReport(code[, iframeId]) – the PDF in a new tab, or in an iframe of the (parent) form. */
	api('nuRunReport', function (code, iFrame) {
		var w = iFrame === undefined || iFrame === null || iFrame === '' ? window.open('', '_blank') : null;   // opened right away: no popup blocker
		if (w) { try { w.document.title = (M.report_running || 'Report …'); w.document.body.innerHTML = '<p style="font:14px system-ui,sans-serif;padding:1rem">' + esc(M.report_running || 'Report …') + '</p>'; } catch (x) {} }
		return reportRun(code).then(function (res) {
			var url = reportUrl(res.id);
			if (w) { w.location.href = url; return res; }
			if (iFrame === undefined || iFrame === null || iFrame === '') { window.open(url, '_blank'); return res; }
			var f = null;
			try { f = (parent && parent.document.getElementById(iFrame)) || g(iFrame); } catch (x) { f = g(iFrame); }
			if (f) f.setAttribute('src', url);
			return res;
		}, function () { if (w) w.close(); });
	});
	/** nuBuilder Next: the PDF in a dialog on this page. */
	api('nuPreviewReport', function (code) {
		return reportRun(code).then(function (res) {
			var m = g('nuReportModal');
			if (!m) { window.open(reportUrl(res.id), '_blank'); return res; }
			$(m).find('iframe').attr('src', reportUrl(res.id));
			$(m).find('.nuReportOpen').attr('href', reportUrl(res.id));
			nuBs.modal(m).show();
			return res;
		}, function () {});
	});
	/** nuBuilder: nuRunReportSave(code, tag, callback(filename, id), fileName) – the PDF is written to the server's temp folder (pdf_temp). */
	api('nuRunReportSave', function (code, tag, callback, fileName) {
		return reportRun(code).then(function (res) {
			return reportPost({action: 'save', i: res.id, tag: tag === null || tag === undefined ? '' : tag, file_name: fileName || ''});
		}).then(function (data) {
			if (data && data.ok === false) nuDisplayError(data);
			if (typeof callback === 'function') callback(data.filename, data.id, data);
			return data;
		}, function () {});
	});
	/** nuBuilder Next: nuEmailReportPDF(code, to, subject, body, fileName[, callback(ok, data)]) – runs the report and e-mails it as PDF attachment. */
	api('nuEmailReportPDF', function (code, to, subject, body, fileName, callback) {
		return reportRun(code).then(function (res) {
			return reportPost({action: 'email', i: res.id, to: to || '', subject: subject || '', body: body || '', file_name: fileName || ''});
		}).then(function (data) {
			if (data && data.ok === false) nuDisplayError(data.errors ? data : {errors: [{message: (data.message || '') + ' ' + (data.info || '')}]});
			else if (window.nuToast) nuToast(M.report_emailed || 'E-mail sent.', 'success');
			if (typeof callback === 'function') callback(!!(data && data.ok), data);
			return data;
		}, function () {});
	});
	$(document).on('click', '#nuRunReportButton', function () { nuRunReport(this.getAttribute('data-nu-report')); });
	api('nuRunReportAction', function (code) { return nuRunReport(code); });

	// =================================================================== print view settings (nuBuilder Forte's nuform.js, 0.8.958)
	// The same properties as Forte – read by the server for the print view and its CSV / Excel export (core/form/nuprintview.php).
	api('nuPrintIncludeColumns', function (arr) { nuSetProperty('nuPrintIncludedColumns', nuEncode(arr)); });
	api('nuPrintExcludeColumns', function (arr) { nuSetProperty('nuPrintExcludedColumns', nuEncode(arr)); });
	api('nuPrintIncludeHiddenColumns', function (include) { nuSetProperty('nuPrintIncludeHiddenColumns', include ? '1' : '0'); });
	api('nuPrintUseBrowseFormats', function (value) { nuSetProperty('nuPrintUseBrowseFormats', value ? '1' : '0'); });
	api('nuPrintCSVExportOptions', function (options) { nuSetProperty('nuPrintCSVExportOptions', nuEncode(JSON.stringify(options || {}))); });
	/** The print view's address with the properties form code set just before (nuPrintIncludeColumns() in nuOnLoad …). */
	function printUrl(href) {
		var u = new URL(href, window.location.href);
		u.searchParams.set('ps', N.ps || '');
		return u.toString();
	}
	$(document).on('click', 'a.nuExpHtml', function (e) {
		if (N.mode !== 'browse' || !N.form) return;
		e.preventDefault();
		var href = this.getAttribute('href');
		go(function () { return printUrl(href); }, 'blank');
	});
	// in the print view: Ctrl+Shift+C copy, Ctrl+Shift+D download CSV, Ctrl+Shift+O the CSV options – as Forte's nurunhtml.php
	$(function () {
		if (!document.body.classList.contains('nuPrintView')) return;
		var box = document.getElementById('nuPrintOpts'), opts = {};
		try { opts = JSON.parse(box ? box.getAttribute('data-opts') || '{}' : '{}') || {}; } catch (x) { opts = {}; }
		var form = document.querySelector('#nuCsvDialog .js-csv-form');
		if (form) {
			var SEP = {';': 'semicolon', ',': 'comma', '\t': 'tab', '\\t': 'tab', '|': 'pipe'};
			var sep = form.querySelector('[name=sep]'), name = form.querySelector('#nuCsvName');
			if (opts.delimiter !== undefined && SEP[opts.delimiter] && sep) sep.value = SEP[opts.delimiter];
			if (opts.fileName && name) name.value = String(opts.fileName).replace(/\.csv$/i, '');
			if (opts.includeHeaders !== undefined) { var hd = form.querySelector('#nuCsvHead'); if (hd) hd.checked = !!opts.includeHeaders; }
			if (opts.bom !== undefined) { var bm = form.querySelector('#nuCsvBom'); if (bm) bm.checked = !!opts.bom; }
		}
		var download = function () { if (!form) return; if (form.requestSubmit) form.requestSubmit(); else form.submit(); };
		document.addEventListener('keydown', function (e) {
			if (!(e.ctrlKey || e.metaKey) || !e.shiftKey) return;
			var k = (e.key || '').toLowerCase();
			if (k === 'd') { e.preventDefault(); download(); }
			else if (k === 'o') { e.preventDefault(); var b = document.getElementById('nuCsvButton'); if (b) b.click(); }
			else if (k === 'c') { var c = document.querySelector('[data-nu-copy-table]'); if (c) { e.preventDefault(); c.click(); } }
		});
		if (opts.autoDownload) {
			download();
			if (opts.autoClose) setTimeout(function () { window.close(); }, 1500);
		}
	});

	// =================================================================== features nuBuilder Next does not implement (yet)
	// (nuEnableMultiSelect, nuShowFile, nuSetDateFormat, nuSetTabHelpButtons, nuSetSideBarStyle are not in nuBuilder Forte 4.9's JavaScript –
	// kept so old code does not fail; nuSetFormValue is PHP only in nuBuilder Forte and supported there, 0.8.876)
	var warned = {};
	['nuResizeBrowseColumns', 'nuSetBrowseColumnWidth', 'nuOpenWiki',
		'nuSetSnippetFormFilter', 'nuEnableMultiSelect', 'nuShowFile',
		'nuSetDateFormat', 'nuCreateDialog', 'nuAccessFormSetButtonIcons', 'nuBrowseStyleStatusColumn',
		'nuSetTabHelpButtons', 'nuDragElement', 'nuSetSideBarStyle', 'nuShowVersionInfo', 'nuHasHiddenModalDragDialog'
	].forEach(function (name) {
		if (window[name]) return;
		window[name] = function () {
			if (!warned[name]) { warned[name] = true; console.warn('nuBuilder Next: ' + name + '() is not supported yet'); }
			return $();
		};
	});
	if (!$.fn.nuSearchablePopup) $.fn.nuSearchablePopup = function () { return this; };
	if (!$.fn.nuSearchableMultiPopup) $.fn.nuSearchableMultiPopup = function () { return this; };
	if (!$.fn.nuCSSNumber) $.fn.nuCSSNumber = function (p) { return parseInt(this.css(p), 10) || 0; };

	// =================================================================== files in fields (nuBuilder format)
	function fileSize(b) { return !b ? '' : (b < 1024 ? b + ' B' : (b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1) + ' MB')); }

	function renderFileInfo(field, json) {
		var box = $(field).find('.nuFileInfo');
		box.children(':not(.nuFileRemove)').remove();   // children only: find() also took the remove button's icon (0.8.682)
		var d = null;
		try { d = json ? JSON.parse(json) : null; } catch (e) { d = null; }
		if (!d || !d.file) { box.addClass('d-none'); return; }
		var url = '';
		try { url = String(d.file).indexOf('data:') === 0 ? d.file : atob(d.file); } catch (e) { url = ''; }
		var isImg = /^image\//.test(d.type || '') && url.indexOf('data:image/') === 0;
		var parts = [isImg ? $('<img class="nuFileThumb" alt="">').attr('src', url) : $('<i class="bi bi-file-earmark fs-4 text-body-secondary"></i>'),
			$('<span class="nuFileName text-truncate"></span>').text(d.name || 'file'), $('<span class="small text-body-secondary"></span>').text(fileSize(d.size))];
		if (url) parts.push($('<a class="btn btn-sm btn-outline-secondary nuFileDownload"><i class="bi bi-download"></i></a>').attr({href: url, download: d.name || 'file'}));
		box.prepend(parts).removeClass('d-none');
	}

	api('nuChangeFile', function (event) {
		var input = event.target, dataId = input.id.replace(/_file$/, ''), data = g(dataId);
		var file = input.files && input.files[0];
		if (!file || !data) return;
		var maxKB = +(N.fileMaxKB || 300);
		var reader = new FileReader();
		reader.onload = function () {
			var json = JSON.stringify({file: btoa(reader.result), name: file.name, size: file.size, type: file.type});
			if (window.nuOnFileLoad) {
				if (window.nuOnFileLoad(event, input.id, json) === false) return;
			} else if (file.size > maxKB * 1024) {
				nuMessage(M.error || 'Error', [(M.file_too_large || 'File too large').replace('%d', maxKB)]);
				input.value = '';
				return;
			}
			data.value = json;
			markEdited(data);
			$(data).trigger('change');
			renderFileInfo(input.closest('.nuFileField'), json);
			input.value = '';
			if (window.nuOnFileLoaded) window.nuOnFileLoaded(event, input.id, json);
		};
		reader.readAsDataURL(file);
	});
	$(document).on('change', '.nuFileInput', function (e) { window.nuChangeFile(e); });
	$(document).on('click', '.nuFileRemove', function () {
		var field = this.closest('.nuFileField'), data = g(field.getAttribute('data-nu-file-for'));
		if (!data) return;
		data.value = '';
		markEdited(data);
		$(data).trigger('change');
		renderFileInfo(field, '');
	});

	// =================================================================== charts (Google Charts) – nuBuilder's nuChart() and chart objects
	/*
	 * nuChart(chartId, chartType, dataArray, chartTitle, xAxisTitle, yAxisTitle, seriesType, isStacked [, options]) – as nuBuilder.
	 * dataArray: name of a global array ('myData', 'obj.data'), an array literal as text, an array, a function returning one, or a
	 * google.visualization.DataTable. Chart objects (type chart) call it by themselves when the form is shown.
	 * Also: the Google loader is loaded once and only when needed; colours follow light / dark mode (redrawn on a switch); the
	 * chart is redrawn when its box changes size (also when a hidden tab is opened); a message instead of a blank box when there
	 * is no data or Google Charts cannot be loaded; options (9th parameter) are merged into the chart options; returns a Promise
	 * with the ChartWrapper; window[chartId + '_wrapper'] and nuChartOnReady(chartId, wrapper) as nuBuilder.
	 */
	var gcPromise = null, chartEls = [];
	function loadGoogleCharts() {
		if (gcPromise) return gcPromise;
		gcPromise = new Promise(function (resolve, reject) {
			var start = function () {
				try {
					google.charts.load('current', {packages: ['corechart'], language: document.documentElement.lang || 'en'});
					google.charts.setOnLoadCallback(function () { resolve(window.google); });
				} catch (e) { reject(e); }
			};
			if (window.google && google.charts && google.charts.load) { start(); return; }
			var s = document.createElement('script');
			s.src = 'https://www.gstatic.com/charts/loader.js';
			s.onload = start;
			s.onerror = function () { reject(new Error('loader')); };
			document.head.appendChild(s);
			setTimeout(function () { reject(new Error('timeout')); }, 20000);
		});
		gcPromise.catch(function () { gcPromise = null; });   // a later call may try again
		return gcPromise;
	}
	function chartData(d) {
		if (typeof d === 'function') d = d();
		if (typeof d === 'string') {
			var s = d.trim();
			if (s === '') return null;
			if (/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/.test(s)) {           // a (global) variable name, as nuBuilder's eval(name)
				d = s.split('.').reduce(function (o, k) { return o == null ? o : o[k]; }, window);
				if (d === undefined) { try { d = (0, eval)('(' + s + ')'); } catch (x) { d = undefined; } }   // eslint-disable-line no-eval -- let / const in the form code
			} else {
				d = (0, eval)('(' + s + ')');                                          // eslint-disable-line no-eval
			}
			if (typeof d === 'function') d = d();
		}
		return d;
	}
	function chartMessage(el, text, kind) {
		var m = el.querySelector('.nuChartMsg');
		if (!m) { m = document.createElement('div'); m.className = 'nuChartMsg'; el.appendChild(m); }
		m.className = 'nuChartMsg' + (kind ? ' nuChartMsg-' + kind : '');
		m.textContent = text;
		m.hidden = false;
	}
	function chartColours() {
		var cs = getComputedStyle(document.body);
		var fg = cs.color, muted = cs.getPropertyValue('--bs-secondary-color').trim() || fg, grid = cs.getPropertyValue('--bs-border-color').trim() || '#dee2e6';
		var ts = {color: fg}, ms = {color: muted};
		return {backgroundColor: 'transparent', fontName: cs.fontFamily.split(',')[0].replace(/["']/g, '').trim(), titleTextStyle: ts, legend: {textStyle: ts},
			hAxis: {textStyle: ms, titleTextStyle: ms, gridlines: {color: grid}, baselineColor: grid},
			vAxis: {textStyle: ms, titleTextStyle: ms, gridlines: {color: grid}, baselineColor: grid},
			pieSliceBorderColor: cs.backgroundColor || 'transparent'};
	}
	api('nuChart', function (chartId, chartType, dataArray, chartTitle, xAxisTitle, yAxisTitle, seriesType, isStacked, options) {
		var el = typeof chartId === 'string' ? g(chartId) : chartId;
		if (!el) return Promise.resolve(null);
		var id = el.id;
		var data;
		try { data = chartData(dataArray); } catch (e) { reportError(e.message, 'nuChart ' + id); data = null; }
		var isTable = data && typeof data.getNumberOfRows === 'function';
		if (!isTable && (!Array.isArray(data) || data.length < 2)) {
			chartMessage(el, (M.chart_no_data || 'No data for the chart') + (typeof dataArray === 'string' && dataArray.trim() ? ': ' + dataArray.trim() : ''), 'muted');
			return Promise.resolve(null);
		}
		return loadGoogleCharts().then(function () {
			var base = {title: chartTitle || '', hAxis: {title: xAxisTitle || ''}, vAxis: {title: yAxisTitle || ''}, isStacked: !!isStacked && isStacked !== 'false'};
			if (seriesType) base.seriesType = seriesType;
			var opts = $.extend(true, chartColours(), base, options || {});
			var table = isTable ? data : google.visualization.arrayToDataTable(data);
			var st = el._nuChart;
			if (st && st.ro) st.ro.disconnect();
			el.textContent = '';
			var wrapper = new google.visualization.ChartWrapper({chartType: chartType || 'ColumnChart', dataTable: table, containerId: id, options: opts});
			var first = true;
			google.visualization.events.addListener(wrapper, 'ready', function () {
				if (first && typeof window.nuChartOnReady === 'function') { first = false; try { window.nuChartOnReady(id, wrapper); } catch (x) { reportError(x.message, 'nuChartOnReady'); } }
			});
			google.visualization.events.addListener(wrapper, 'error', function (e) { reportError(e.message, 'nuChart ' + id); });
			if (chartEls.indexOf(el) < 0) chartEls.push(el);
			st = el._nuChart = {wrapper: wrapper, base: $.extend(true, {}, base, options || {}), w: 0, h: 0, timer: null};
			var draw = function () {
				var r = el.getBoundingClientRect();
				if (!r.width || !r.height) return;                       // hidden tab: drawn when it is shown
				if (Math.abs(r.width - st.w) < 2 && Math.abs(r.height - st.h) < 2) return;
				st.w = r.width; st.h = r.height;
				wrapper.draw();
			};
			if (window.ResizeObserver) {
				st.ro = new ResizeObserver(function () { clearTimeout(st.timer); st.timer = setTimeout(draw, 120); });
				st.ro.observe(el);
			}
			draw();
			window[id + '_wrapper'] = wrapper;
			return wrapper;
		}, function () {
			chartMessage(el, M.chart_load_failed || 'Google Charts could not be loaded (www.gstatic.com).', 'error');
			return null;
		});
	});
	// light / dark switch: redraw with the new colours
	if (window.MutationObserver) new MutationObserver(function () {
		chartEls = chartEls.filter(function (el) { return el.isConnected; });
		chartEls.forEach(function (el) {
			var st = el._nuChart;
			if (!st || !st.wrapper) return;
			st.wrapper.setOptions($.extend(true, chartColours(), st.base));
			st.w = 0; st.h = 0;
			if (el.getBoundingClientRect().width) { st.w = el.getBoundingClientRect().width; st.h = el.getBoundingClientRect().height; st.wrapper.draw(); }
		});
	}).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});
	function initCharts(root) {
		$(root || document).find('.nuChart').each(function () {
			if (this._nuChart || this.closest('template')) return;
			var d = this.dataset;
			if (!d.nuChartType) { chartMessage(this, M.chart_no_type || 'Choose a chart type in the builder.', 'muted'); return; }
			nuChart(this.id, d.nuChartType, d.nuChartData || '', d.nuChartTitle, d.nuChartHtitle, d.nuChartVtitle, d.nuChartSeries, d.nuChartStacked === '1');
		});
	}
	N.initCharts = initCharts;

	// =================================================================== form buttons (input + run objects, 0.8.621)
	// 1. Text colour: a button the form's CSS gives its own background (as usual for nuBuilder Forte – #id { background-color: … })
	//    keeps white text as in nuBuilder Forte, dark text only on a light background (white below 2.4:1). Only while the text colour
	//    is still ours (--nu-btn-ink): a colour the form's CSS sets itself stays. Classes nuBtnInkLight / nuBtnInkDark (nuform.css).
	// 2. One line: a label cut off with … gets the full label (+ the new-tab / popup hint) as tooltip, checked again when the size
	//    changes (a button on a hidden tab has no size until the tab is shown).
	var btnProbe = null, btnSizes = window.ResizeObserver ? new ResizeObserver(function (es) { es.forEach(function (e) { fitButtonTitle(e.target); }); }) : null;
	function cssRgb(v) {                                               // any CSS colour → [r, g, b, a] (through a probe element)
		v = String(v || '').trim();
		if (!v) return null;
		if (!btnProbe) { btnProbe = document.createElement('span'); btnProbe.hidden = true; document.body.appendChild(btnProbe); }
		btnProbe.style.color = ''; btnProbe.style.color = v;
		if (!btnProbe.style.color) return null;
		var m = getComputedStyle(btnProbe).color.match(/[\d.]+/g);
		return m && m.length >= 3 ? [+m[0], +m[1], +m[2], m.length > 3 ? +m[3] : 1] : null;
	}
	function sameRgb(a, b) { return !!a && !!b && Math.abs(a[0] - b[0]) < 2 && Math.abs(a[1] - b[1]) < 2 && Math.abs(a[2] - b[2]) < 2; }
	function luminance(c) {
		var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
		return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
	}
	function fitButtonInk(b) {
		b.classList.remove('nuBtnInkLight', 'nuBtnInkDark');
		var c = getComputedStyle(b);
		if (c.backgroundImage && c.backgroundImage !== 'none') return;            // gradient / image: cannot judge it
		var bg = cssRgb(c.backgroundColor);
		if (!bg || bg[3] < 0.5 || sameRgb(bg, cssRgb(c.getPropertyValue('--bs-btn-bg')))) return;   // transparent, or the look's own colour
		if (!sameRgb(cssRgb(c.color), cssRgb(c.getPropertyValue('--nu-btn-ink')))) return;           // text colour set by the form's CSS
		b.classList.add(1.05 / (luminance(bg) + 0.05) >= 2.4 ? 'nuBtnInkLight' : 'nuBtnInkDark');
	}
	// narrow buttons (nuBuilder Forte's quick buttons of 50–60 px: "+27", "100"): the solid look's side padding would cut the
	// label – less padding first (nuBtnTight 6 px, nuBtnTighter 2 px), the tooltip only when the label still does not fit (0.8.960)
	// cut = the label does not fit: the button's own overflow, or its label span's (text-overflow: ellipsis) – a label 0.7 px too wide
	// was cut to "…" while the button's rounded scrollWidth still fitted (0.8.974)
	function btnCut(b) {
		if (b.scrollWidth > b.clientWidth + 1) return true;
		var lab = b.querySelector('.nuButtonLabel');
		if (!lab || !b.clientWidth) return false;
		var cs = getComputedStyle(b), room = b.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
		var used = lab.getBoundingClientRect().width, icon = b.querySelector('.nuObjIcon');
		if (icon) used += icon.getBoundingClientRect().width + 4;
		return used > room + 0.05;
	}
	function fitButtonPad(b) {
		b.classList.remove('nuBtnTight', 'nuBtnTighter', 'nuBtnWrap');
		if (!btnCut(b)) return;
		// a button tall enough for two lines wraps its label, as in nuBuilder Forte (a 47 px button showed "Feedback Tool -…")
		if (b.clientHeight >= 38) {
			b.classList.add('nuBtnWrap');
			if (!btnCut(b) && b.scrollHeight <= b.clientHeight + 1) return;
			b.classList.remove('nuBtnWrap');
		}
		b.classList.add('nuBtnTight');
		if (btnCut(b)) b.classList.add('nuBtnTighter');
	}
	function fitButtonTitle(b) {
		if (!b.hasAttribute('data-nu-title0')) b.setAttribute('data-nu-title0', b.getAttribute('title') || '');
		if (!b.clientWidth) return;
		fitButtonPad(b);
		var t0 = b.getAttribute('data-nu-title0'), hint = b.getAttribute('data-nu-run-hint') || '';
		var lab = b.querySelector('.nuButtonLabel'), text = (lab ? lab.textContent : b.textContent).replace(/\s+/g, ' ').trim();
		if (btnCut(b) && text) b.setAttribute('title', text + (hint ? ' – ' + hint : ''));
		else if (t0) b.setAttribute('title', t0);
		else b.removeAttribute('title');
	}
	// =================================================================== phones: fixed-position forms stacked (review F1, 0.8.764)
	// Below 576 px the objects of an absolute layout keep no top / left: html.nuPhoneStack (nuform.css) puts them below each other,
	// label above the field, full width; the order follows top, then left (style.order). Content boxes become a section title.
	// Off for one form: nuNextPhoneLayout(false) in nuOnLoad (or Global JS); not while designing.
	var phoneOff = false, phoneMq = window.matchMedia ? window.matchMedia('(max-width: 575.98px)') : null;
	api('nuNextPhoneLayout', function (on) { phoneOff = on === false; phoneStack(); });
	// nuBuilder Forte's nuPortraitScreen(columns): the edit form in one column at any width – here the phone layout (labels above the
	// fields, html.nuPortrait caps the width on wide screens); lists are left as they are, as in Forte. Returns the scale (always 1).
	var portrait = false;
	api('nuPortraitScreen', function () {
		if (N.mode !== 'edit') return 1;
		portrait = true;
		window.nuPORTRAITSCREEN = true;
		phoneStack();
		return 1;
	});
	function phoneListKey() { return 'nunext-browse-phone-' + ((N.form && N.form.id) || ''); }
	function phoneListPref() { try { return localStorage.getItem(phoneListKey()) || ''; } catch (x) { return ''; } }
	$(document).on('click', '#nuPhoneCardsBtn', function () {
		var next = document.documentElement.classList.contains('nuPhoneCards') ? 'table' : 'cards';
		try { if (next === 'cards') localStorage.removeItem(phoneListKey()); else localStorage.setItem(phoneListKey(), next); } catch (x) { /* storage blocked */ }
		phoneStack();
		if (window.nuStickyHeadCheck) window.nuStickyHeadCheck();
	});
	function phoneStack() {
		var html = document.documentElement;
		var phone = !!(phoneMq && phoneMq.matches) && !phoneOff && !html.classList.contains('nuDesigning');
		var forced = portrait && !phoneOff && !html.classList.contains('nuDesigning');
		// a form in a dialog (iframe) of a wide window keeps the desktop layout – a narrow dialog is not a phone (0.8.965)
		if (phone && N.iframe) { try { if (window.top !== window && window.top.innerWidth >= 576) phone = false; } catch (x) { /* another origin */ } }
		// browse rows as cards (F2) – unless the user chose the table with the ▦ / ☰ button (0.8.992, per form on this device)
		var cards = phone && !!document.getElementById('nuBrowseTable') && !document.body.classList.contains('nuPrintView') && phoneListPref() !== 'table';
		html.classList.toggle('nuPhoneCards', cards);
		var pb = document.getElementById('nuPhoneCardsBtn');
		if (pb) { pb.setAttribute('aria-pressed', cards ? 'false' : 'true'); pb.classList.toggle('nuPhoneIsTable', !cards); }
		var pa = document.querySelector('.nuPhAdd'), ab = g('nuAddButton');   // phones: Add next to the search only while Add exists (form code may hide it, 0.8.1003)
		if (pa) pa.hidden = !ab || ab.hidden || ab.style.display === 'none';
		var on = (phone || forced) && !!document.querySelector('.nuLayout-absolute .nuAbsoluteCanvas');
		html.classList.toggle('nuPhoneStack', on);
		html.classList.toggle('nuPortrait', on && forced);
		if (!on) return;
		$('.nuLayout-absolute .nuAbsoluteCanvas').each(function () {
			$(this).children('.nuAbs').each(function () {
				var t = parseInt(this.style.top, 10) || 0, l = parseInt(this.style.left, 10) || 0;
				this.style.order = String(t * 10 + Math.min(9, Math.floor(l / 100)));   // rows first, left to right within ~ a row
			});
		});
	}
	if (phoneMq) { try { phoneMq.addEventListener('change', function () { phoneStack(); growFrames(); }); } catch (x) { /* old Safari */ } }

	// embedded lists (review F3): a form shown in a run object of another form gets html.nuEmbedded (compact chrome, nuform.css);
	// the parent grows such iframes to their content while its objects flow (phones, auto / grid layouts) – absolute layouts keep their size
	if (N.iframe) { try { if (window.frameElement && !window.frameElement.closest('.modal')) document.documentElement.classList.add('nuEmbedded'); } catch (x) { /* other origin */ } }
	// framed forms of run objects (0.8.962): src only now – the parent's functions (nuSetValue …) exist before the frame's code runs
	// 0.8.974: in nuBuilder Forte the list's search field and buttons sit in #nuActionHolder – form code that hides the holder (or
	// #nuSearchField) at load hides the search row; the title bar goes when nothing in it is left
	function forteHidden() {
		if (N.mode !== 'browse') return;
		var off = function (sel) { var e = document.querySelector(sel); return !!e && e.style.display === 'none'; };
		var form = document.querySelector('.nuBrowseSearch');
		if (form && off('#nuActionHolder')) form.style.display = 'none';
		else if (off('#nuSearchField')) $('.nuSearchGroup').hide();
		var head = document.querySelector('.nuFormHeader');
		if (head && off('#nuActionHolder') && off('#nuBreadcrumbHolder')) head.style.display = 'none';
	}

	// 0.8.974: properties the form's code set at load (nuSetProperty before the frames load) reach the framed form, as in Forte
	$(function () {
		var fr = $('iframe.nuIframe[data-nu-src]').filter(function () { return !this.getAttribute('src'); });
		if (!fr.length) return;
		withScope(function (ps) {
			fr.each(function () {
				var src = this.getAttribute('data-nu-src');
				if (ps) {
					try { var u = new URL(src, location.href); u.searchParams.set('ps', ps); src = u.pathname + u.search + u.hash; } catch (x) { /* keep src */ }
				}
				if (!this.getAttribute('src')) this.setAttribute('src', src);
			});
		});
	});
	function growFrames() {
		$('.nuWrap_run iframe.nuIframe').each(function () {
			var f = this;
			var fit = function () {
				var flow = document.documentElement.classList.contains('nuPhoneStack') || !f.closest('.nuAbs');
				if (!f.dataset.nuH0) f.dataset.nuH0 = f.style.height || '';
				if (!flow) { f.style.height = f.dataset.nuH0; return; }
				try {
					var d = f.contentDocument;
					if (!d || !d.body) return;
					var main = d.getElementById('main') || d.body;
					var h = Math.ceil(main.getBoundingClientRect().height + (main === d.body ? 0 : main.getBoundingClientRect().top + 8));
					if (h > 40) { f.style.height = Math.min(h, 2400) + 'px'; f.parentElement.style.height = 'auto'; }
				} catch (x) { /* other origin: keep the size */ }
			};
			// fit now and whenever the framed page changes size – also when it had loaded before this ran (0.8.886: the observer was
			// only attached on a later load event, so a frame that was already loaded kept its first, too small height)
			var watch = function () {
				fit();
				try {
					var d = f.contentDocument, el = d && (d.getElementById('main') || d.body);
					if (!el || !window.ResizeObserver || f.__nuObserved === el) return;
					if (f.__nuObs) f.__nuObs.disconnect();
					f.__nuObs = new ResizeObserver(fit); f.__nuObs.observe(el); f.__nuObserved = el;
				} catch (x) { /* other origin */ }
			};
			if (f.__nuGrow) { watch(); return; }
			f.__nuGrow = true;
			f.addEventListener('load', watch);
			try { if (f.contentDocument && f.contentDocument.readyState === 'complete' && f.contentDocument.body && f.contentDocument.body.childElementCount) watch(); } catch (x) { /* ignore */ }
		});
	}

	function fitButtons(root) {
		$(root || document).find('.btn.nuFormBtn').each(function () {
			if (this.closest('template') || /(^|\s)btn-/.test(this.className)) return;
			try { fitButtonInk(this); } catch (x) { /* ignore */ }
			fitButtonTitle(this);
			if (btnSizes) btnSizes.observe(this);
		});
	}
	N.fitButtons = fitButtons;

	// the fixed layout's canvas grows with what its objects show (0.8.965): an HTML object whose content the form's code fills later (a
	// table built on a tab click …) runs out of its stored height – nuBuilder Forte's page then simply gets longer; the canvas never
	// shrinks below the height the form was drawn with
	function fitCanvas(c) {
		if (!c || document.documentElement.classList.contains('nuPhoneStack') || !c.offsetParent) return;
		var base = +c.getAttribute('data-nu-h') || 0;
		if (!base) { base = parseFloat(c.style.height) || c.offsetHeight; c.setAttribute('data-nu-h', String(base)); }
		var need = base;
		Array.prototype.forEach.call(c.children, function (el) {
			if (!el.classList.contains('nuAbs') || el.offsetParent === null) return;
			need = Math.max(need, el.offsetTop + Math.max(el.offsetHeight, el.scrollHeight) + 10);
		});
		if (Math.abs(need - (parseFloat(c.style.height) || 0)) > 1) c.style.height = need + 'px';
	}
	function fitCanvases() { document.querySelectorAll('.nuLayout-absolute .nuAbsoluteCanvas').forEach(fitCanvas); }
	var canvasRO = typeof window.ResizeObserver === 'function' ? new window.ResizeObserver(function (en) {
		en.forEach(function (x) { fitCanvas(x.target.closest && x.target.closest('.nuAbsoluteCanvas')); });
	}) : null;
	function watchCanvases() {
		if (!canvasRO) return;
		document.querySelectorAll('.nuLayout-absolute .nuAbsoluteCanvas > .nuAbs .nuHtml > *').forEach(function (el) { canvasRO.observe(el); });
	}
	$(document).on('shown.bs.tab', '.nuTab', function () { setTimeout(fitCanvases, 30); });
	// fixed-layout forms wider than the window: nuBuilder Next's page margins (main + the tab pane) took ~55 px more than Forte's, so a
	// form that fits Forte's window needed a sideways scroll. While a canvas would scroll, html.nuWideForm takes the margins away (form
	// card to the window edges, no pane padding) and the canvas is only as wide as its objects (no 20 px gap after the last one)
	function fitWide() {
		var root = document.documentElement, form = document.getElementById('nuEditForm');
		if (N.mode !== 'edit' || !form || root.classList.contains('nuPhoneStack') || root.classList.contains('nuDesigning')) { root.classList.remove('nuWideForm'); return; }
		var canvases = Array.prototype.filter.call(document.querySelectorAll('.nuLayout-absolute .nuAbsoluteCanvas'), function (c) { return c.offsetParent !== null; });
		root.classList.remove('nuWideForm');
		form.style.marginLeft = form.style.marginRight = '';
		var wide = false;
		canvases.forEach(function (c) {
			if (!c.hasAttribute('data-nu-minw')) c.setAttribute('data-nu-minw', c.style.minWidth || '');
			c.style.minWidth = c.getAttribute('data-nu-minw');
			var right = 0;
			Array.prototype.forEach.call(c.children, function (el) { if (el.classList.contains('nuAbs') && el.offsetParent !== null) right = Math.max(right, el.offsetLeft + el.offsetWidth); });
			var pane = c.parentElement, ps = getComputedStyle(pane), room = pane.clientWidth - parseFloat(ps.paddingLeft) - parseFloat(ps.paddingRight);
			if (c.offsetWidth > room + 1) { wide = true; c.setAttribute('data-nu-right', String(right)); }
		});
		if (!wide) return;
		var main = form.closest('main'), ms = main ? getComputedStyle(main) : null;
		root.classList.add('nuWideForm');
		if (ms) { form.style.marginLeft = '-' + ms.paddingLeft; form.style.marginRight = '-' + ms.paddingRight; }
		canvases.forEach(function (c) { var r = +c.getAttribute('data-nu-right'); if (r) c.style.minWidth = r + 'px'; });
	}
	var wideTimer = 0;
	$(window).on('resize', function () { clearTimeout(wideTimer); wideTimer = setTimeout(fitWide, 150); });
	$(document).on('shown.bs.tab', '.nuTab', function () { setTimeout(fitWide, 40); });
	N.fitWide = fitWide;
	N.fitCanvases = fitCanvases;

	// read-only text fields of the fixed layout (Forte's widths): nuBuilder Next's font is a little wider, so a value like
	// "2026-11-02 18:11:05" may be cut. The field grows to the right while nothing stands there (other objects of the same height
	// band, the content box around it, the canvas), at most by the missing width; what is still cut shows as tooltip.
	function fitReadonly(root) {
		$(root || document).find('.nuAbsoluteCanvas > .nuAbs input.form-control').each(function () {
			var el = this, wrap = el.closest('.nuAbs');
			if (!(el.readOnly || el.disabled) || el.type === 'hidden' || el.closest('template') || !el.offsetWidth) return;
			var need = el.scrollWidth - el.clientWidth;
			if (need <= 0) return;
			var canvas = wrap.parentNode, r = wrap.getBoundingClientRect(), limit = canvas.getBoundingClientRect().right - 4;
			Array.prototype.forEach.call(canvas.children, function (o) {
				if (o === wrap || !o.offsetWidth || o.tagName === 'TEMPLATE') return;
				var q = o.getBoundingClientRect();
				if (q.bottom <= r.top || q.top >= r.bottom) return;                              // another height band
				if (q.left <= r.left && q.right >= r.right) { limit = Math.min(limit, q.right - 10); return; }   // a box around it
				if (q.left >= r.right - 1) limit = Math.min(limit, q.left - 8);                 // something to the right
			});
			var grow = Math.max(0, Math.min(need + 2, Math.floor(limit - r.right)));
			if (grow > 0) wrap.style.width = (wrap.offsetWidth + grow) + 'px';
			if (el.scrollWidth > el.clientWidth && !el.title) el.title = el.value;
		});
	}
	N.fitReadonly = fitReadonly;
	new MutationObserver(function () { if (N.mode === 'edit') fitButtons(document); })
		.observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});

	// =================================================================== searchable / multiple selects (Tom Select)
	/** nuBuilder Forte 4.9.2: a select whose validation allows no blank has no empty item – with nothing chosen no option is
	 *  selected (selectedIndex -1, $(el).val() = null, nuGetValue() = ''), not the first one the browser would pick (0.8.958). */
	function noBlank(root) {
		$(root || document).find('select[data-nu-noblank]').each(function () {
			if (this.closest('template')) return;
			for (var i = 0; i < this.options.length; i++) if (this.options[i].defaultSelected) return;
			this.selectedIndex = -1;
		});
	}
	function initSelects(root) {
		noBlank(root);
		if (!window.TomSelect) return;
		$(root || document).find('select.nuSelect2').each(function () { initSelect(this); });
	}
	/** One searchable select. extra: Select2 options of nuSetSelect2 / $.fn.select2 (the same mapping as nuOnSetSelect2Options). */
	function initSelect(el, extra) {
		if (!window.TomSelect || el.tomselect || el.closest('template')) return el.tomselect || null;
		{
			var multi = el.multiple;
			// dropdown in <body>: every form object has its own z-index (and subforms scroll), so a dropdown inside the object
			// opened behind later objects or was cut off. In <body> it lies on top of everything (nuform.css: .nuTsDropdown).
			var tsOpt = {
				plugins: multi ? ['remove_button'] : [], allowEmptyOption: true, maxOptions: 1000, create: false,
				dropdownParent: 'body',
				dropdownClass: 'ts-dropdown nuTsDropdown' + (el.closest('.nuAbs, .nuSubformTable') ? ' nuTsSmall' : ''),
				render: {no_results: function () { return '<div class="no-results">–</div>'; }}
			};
			// nuBuilder: nuOnSetSelect2Options(id, {options}) – the Select2 options Tom Select also knows are taken over;
			// {tomselect: {…}} passes Tom Select options directly (0.8.345)
			if (typeof window.nuOnSetSelect2Options === 'function' || extra) {
				var s2 = {options: $.extend({placeholder: el.getAttribute('placeholder') || ''}, extra || {})};
				var u = typeof window.nuOnSetSelect2Options === 'function' ? callHook('nuOnSetSelect2Options', el.id, s2) : null;
				var so = $.extend({}, u && typeof u === 'object' ? u : {}, s2.options);
				if (Array.isArray(so.data)) so.data.forEach(function (d) {                       // Select2's data: [{id, text}]
					if (d && d.id !== undefined && !$(el).find('option').filter(function () { return this.value === String(d.id); }).length) $(el).append(new Option(String(d.text !== undefined ? d.text : d.id), String(d.id)));
				});
				if (so.placeholder) tsOpt.placeholder = String(so.placeholder);
				if (so.allowClear) tsOpt.plugins = tsOpt.plugins.concat(['clear_button']);
				if (so.maximumSelectionLength > 0) tsOpt.maxItems = so.maximumSelectionLength;
				if (so.minimumResultsForSearch === Infinity || so.minimumResultsForSearch < 0) tsOpt.controlInput = null;
				if (so.tags === true) tsOpt.create = true;
				if (so.tomselect && typeof so.tomselect === 'object') $.extend(tsOpt, so.tomselect);
			}
			var ts = new TomSelect(el, tsOpt);
			if (el.disabled) ts.disable();
			// custom code changes the <select> options directly: keep Tom Select in sync
			// (the observer is paused while Tom Select itself rewrites the options, otherwise it would loop)
			var obs = new MutationObserver(function () {
				obs.disconnect();
				ts.clearOptions();
				ts.sync();
				obs.takeRecords();
				obs.observe(el, {childList: true});
			});
			obs.observe(el, {childList: true});
			$(el).on('change', function () {
				var v = $(el).val(), cur = ts.getValue();
				if (JSON.stringify(multi ? (v || []) : (v || '')) !== JSON.stringify(multi ? (cur || []) : (cur || ''))) ts.setValue(v, true);
			});
			return ts;
		}
	}
	N.initSelects = initSelects;

	// nuBuilder's nuSetSelect2(id, obj) (0.8.370): makes a select searchable – here with Tom Select; obj {top, left, width} places the box
	// on forms with absolute layout as nuBuilder does; returns the id of the box (id + '_select2'). nuSelect2(id): the same (older code).
	function setSelect2(id, obj) {
		var el = g(id);
		if (!el || el.tagName !== 'SELECT') return null;
		el.classList.add('nuSelect2');
		el.setAttribute('data-nu-select2', '1');
		var ts = initSelect(el);
		var boxId = id + '_select2';
		if (ts && ts.wrapper) {
			ts.wrapper.id = boxId;
			if (obj && typeof obj === 'object' && el.closest('.nuAbs')) {
				['top', 'left', 'width'].forEach(function (k) { if (obj[k] !== undefined && obj[k] !== '' && !isNaN(Number(obj[k]))) ts.wrapper.style[k] = Number(obj[k]) + 'px'; });
				if (obj.top !== undefined || obj.left !== undefined) ts.wrapper.style.position = 'absolute';
			}
		}
		return boxId;
	}
	api('nuSetSelect2', setSelect2);
	api('nuSelect2', function (id, obj) { return setSelect2(id, obj); });

	// form code that uses Select2 itself: $('#x').select2({…}) / .select2('open' | 'close' | 'destroy' | 'data' | 'isOpen' | 'focus') –
	// the same calls on Tom Select (0.8.370); without jQuery's own Select2 plug-in only
	if (window.jQuery && !$.fn.select2) {
		$.fn.select2 = function (arg) {
			if (typeof arg === 'string') {
				var first = this.filter('select')[0], ts = first && first.tomselect;
				switch (arg) {
					case 'open': this.each(function () { if (this.tomselect) this.tomselect.open(); }); return this;
					case 'close': this.each(function () { if (this.tomselect) this.tomselect.close(); }); return this;
					case 'focus': if (ts) ts.focus(); return this;
					case 'isOpen': return !!(ts && ts.isOpen);
					case 'enable': this.each(function () { if (this.tomselect) this.tomselect.enable(); }); return this;
					case 'disable': this.each(function () { if (this.tomselect) this.tomselect.disable(); }); return this;
					case 'destroy': this.each(function () { if (this.tomselect) { this.tomselect.destroy(); this.classList.remove('nuSelect2'); } }); return this;
					case 'data':
						if (!first) return [];
						return $(first).find('option:selected').map(function () { return {id: this.value, text: this.text, element: this, selected: true}; }).get();
					default: return this;
				}
			}
			var opt = arg && typeof arg === 'object' ? arg : {};
			this.filter('select').each(function () {
				if (this.tomselect) return;
				if (opt.multiple === true) this.multiple = true;
				this.classList.add('nuSelect2');
				initSelect(this, opt);
			});
			return this;
		};
	}

	// an open dropdown in <body> follows its field when the form or a subform scrolls (Tom Select only listens to the window)
	document.addEventListener('scroll', function (e) {
		if (e.target === document || (e.target.closest && e.target.closest('.ts-dropdown'))) return;
		$('select.nuSelect2').each(function () {
			var ts = this.tomselect;
			if (!ts || !ts.isOpen) return;
			if (!e.target.contains || !e.target.contains(ts.wrapper)) return;   // something else scrolled
			var r = ts.control.getBoundingClientRect(), box = e.target.getBoundingClientRect();
			if (r.bottom < box.top || r.top > box.bottom) ts.close();           // field scrolled out of view
			else ts.positionDropdown();
		});
	}, true);

	// =================================================================== browse filters
	function reloadBrowse(extra) {
		go(function () {
			var u = new URL(window.location.href);
			u.searchParams.set('ps', N.ps || '');
			u.searchParams.delete('pg');
			Object.keys(extra || {}).forEach(function (k) { if (extra[k] === null || extra[k] === '') u.searchParams.delete(k); else u.searchParams.set(k, extra[k]); });
			return u.toString();
		});
	}
	N.reloadBrowse = reloadBrowse;

	/** nuBuilder: nuAddBrowseFilter(columnId or index).nuSearchablePopup({items}) → property "<id>_filter" used as #<id>_filter# in the browse SQL. */
	api('nuAddBrowseFilter', function (column) {
		var isNum = typeof column === 'number';
		var th = isNum ? g('nuBrowseTitle' + column) : document.querySelector('th[data-nu-browse-id="' + column + '"]');
		var key = (isNum ? 'nuBrowseTitle' + column : column) + '_filter';
		var obj = $(th);
		obj.nuSearchablePopup = function (settings) {
			if (!th) return obj;
			var items = (settings && settings.items) || [];
			if (typeof items === 'string') { try { items = JSON.parse(items); } catch (e) { items = []; } }
			if (settings && settings.multiSelect === true) return multiFilter(th, key, items, settings, obj);   // several values (0.8.382)
			var sel = $('<select class="form-select form-select-sm nuBrowseFilterSelect"></select>').attr('aria-label', th.textContent.trim());
			sel.append($('<option value=""></option>').text(M.all || '–'));
			items.forEach(function (it) {
				if (Array.isArray(it) && it.length === 1) it = it[0];
				var v = Array.isArray(it) ? it[0] : it, l = Array.isArray(it) ? (it[1] !== undefined ? it[1] : it[0]) : it;
				if (v === null || v === undefined || String(v) === '') return;
				sel.append($('<option></option>').val(String(v)).text(String(l)));
			});
			var cur = N.props[key] || '';
			sel.val(cur);
			if (cur !== '') $(th).addClass('nuFiltered');
			sel.on('click', function (e) { e.stopPropagation(); });
			sel.on('change', function () { nuSetProperty(key, sel.val()); reloadBrowse(); });
			$(th).find('.nuBrowseFilterSelect').remove();
			$(th).append(sel);
			return obj;
		};
		// nuBuilder Forte 4.9.2: the same with several values, no multiSelect option needed (0.8.960)
		obj.nuSearchableMultiPopup = function (settings) { return obj.nuSearchablePopup($.extend({}, settings || {}, {multiSelect: true})); };
		return obj;
	});

	/**
	 * nuBuilder 4.9: nuAddBrowseFilter(col).nuSearchablePopup({items, multiSelect: true}) – a list of check boxes; the property
	 * "<id>_filter" gets the chosen values as a comma list (nu_filter_match / FIND_IN_SET in the browse SQL). 0.8.382
	 */
	function multiFilter(th, key, items, settings, obj) {
		var chosen = String(N.props[key] || '').split(',').filter(function (v) { return v !== ''; });
		var box = $('<details class="nuBrowseFilterMulti"></details>');
		var sum = $('<summary class="btn btn-sm btn-outline-secondary"></summary>').attr('aria-label', th.textContent.trim());
		var menu = $('<div class="nuBrowseFilterMenu shadow-sm"></div>');
		var all = items.slice();
		if (settings.emptyText !== false) all.push([settings.emptyValue !== undefined ? String(settings.emptyValue) : '-1', settings.emptyText || M.empty || '(empty)']);
		all.forEach(function (it, i) {
			if (Array.isArray(it) && it.length === 1) it = it[0];
			var v = Array.isArray(it) ? it[0] : it, l = Array.isArray(it) ? (it[1] !== undefined ? it[1] : it[0]) : it;
			if (v === null || v === undefined || String(v) === '') return;
			var id = 'nuBf_' + key.replace(/\W/g, '_') + '_' + i;
			var cb = $('<input type="checkbox" class="form-check-input">').attr('id', id).val(String(v)).prop('checked', chosen.indexOf(String(v)) >= 0);
			menu.append($('<div class="form-check"></div>').append(cb, $('<label class="form-check-label"></label>').attr('for', id).text(String(l))));
		});
		var apply = $('<button type="button" class="btn btn-sm btn-primary mt-2"></button>').text(M.ok || 'OK');
		var clear = $('<button type="button" class="btn btn-sm btn-outline-secondary mt-2 ms-1"></button>').text(M.all || '–');
		menu.append($('<div></div>').append(apply, clear));
		var label = function () { sum.text(chosen.length ? '(' + chosen.length + ')' : (M.all || '–')); };
		label();
		apply.on('click', function () {
			var vals = menu.find('input:checked').map(function () { return this.value; }).get();
			nuSetProperty(key, vals.join(','));
			reloadBrowse();
		});
		clear.on('click', function () { nuSetProperty(key, ''); reloadBrowse(); });
		box.on('click', function (e) { e.stopPropagation(); });
		box.append(sum, menu);
		if (chosen.length) $(th).addClass('nuFiltered');
		$(th).find('.nuBrowseFilterSelect, .nuBrowseFilterMulti').remove();
		$(th).append(box);
		return obj;
	}

	/**
	 * nuBuilder 4.9: nuAddBrowseTitleSelect(column, options[, width, style]) – a select under a column title; its value is the property
	 * nuBrowseTitle<n>_select (or nuBrowseTitle_<browse id>_select for an id), used as #…_select# in the browse SQL. 0.8.382
	 */
	api('nuAddBrowseTitleSelect', function (column, optionsData, customWidth, style) {
		if (!Array.isArray(optionsData) || !optionsData.length) return null;
		var isNum = typeof column === 'number';
		var th = isNum ? g('nuBrowseTitle' + column) : document.querySelector('th[data-nu-browse-id="' + column + '"]');
		if (!th) { console.warn('nuAddBrowseTitleSelect: no column ' + column); return null; }   // eslint-disable-line no-console
		var index = isNum ? column : +th.getAttribute('data-nu-col');
		var prop = isNum ? 'nuBrowseTitle' + column + '_select' : 'nuBrowseTitle_' + column + '_select';
		var sel = $('<select class="form-select form-select-sm nuBrowseFilterSelect"></select>').attr('id', 'nuBrowseTitle' + index + '_select').attr('aria-label', th.textContent.trim());
		var simple = !Array.isArray(optionsData[0]);
		optionsData.forEach(function (it) {
			var v = simple ? it : it[0], l = simple ? it : (it[1] !== undefined ? it[1] : it[0]);
			sel.append($('<option></option>').val(v === null || v === undefined ? '' : String(v)).text(l === null || l === undefined ? '' : String(l)));
		});
		if (customWidth !== undefined && customWidth !== null && customWidth !== '') sel[0].style.width = typeof customWidth === 'number' ? customWidth + 'px' : String(customWidth);
		if (style && typeof style === 'object') Object.keys(style).forEach(function (k) { try { sel[0].style[k] = style[k]; } catch (e) { /* ignore */ } });
		if (!isNum) sel.attr('data-nu-title-id', column);
		var cur = N.props[prop];
		if (cur !== undefined && cur !== null) sel.val(String(cur));
		if (sel.val() && String(cur || '') !== '') $(th).addClass('nuFiltered');
		sel.on('click mousedown', function (e) { e.stopPropagation(); });
		sel.on('change', function () { nuSetProperty(prop, sel.val()); reloadBrowse(); });
		$(th).find('#' + sel.attr('id')).remove();
		$(th).append(sel);
		return sel;
	});

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
	// "Edit browse SQL…" in the ▾ of the list's Design button (0.8.930): the form's browse SQL in a pane on the right, saved with the
	// builder API (form.save – the open draft when one is previewed, a live change while a draft is open is confirmed) and reloaded
	(function () {
		var B = N.browse_sql, btn = document.getElementById('nuBrowseSqlEdit');
		if (!B || !btn) return;
		var T = B.m, pane = null, cm = null, PIN = 'nunext-bsq-pin', TAB = 'nunext-bsq-tab', L = B.list || {}, ST = null, COLS = null, names = null;
		// code tabs (0.8.1108): browse SQL, PHP Before Browse and the list's JavaScript – one editor each, made when its tab is first shown
		var TABS = ['sql', 'php', 'js', 'cols'], OPEN = {};
		var CODE = {sql: {cm: null, orig: B.sql, mode: 'text/x-sql', lang: 'SQL'}, php: {cm: null, orig: L.php || '', mode: 'application/x-httpd-php-open', lang: 'PHP'},
			js: {cm: null, orig: L.js || '', mode: 'javascript', lang: 'JS'}};
		function codeVal(k) { return CODE[k].cm ? CODE[k].cm.getValue() : CODE[k].orig; }
		function codeDirty() { return ['sql', 'php', 'js'].some(function (k) { return CODE[k].cm && CODE[k].cm.getValue() !== CODE[k].orig; }); }
		// tab Columns (0.8.942): the list's settings and columns as design mode's Browse list, edited here and saved with the pane's Save
		function stInit() {
			var d = L.details || {};
			ST = {rows: L.rows || '', rowh: L.rowh || '', target: L.target || '0', redirect: L.redirect || '', multi: !!L.multi, autofit: !!L.autofit, dcols: !!d.cols, dsub: d.sub || ''};
			COLS = (L.cols || []).map(function (c) { return {id: c[0], title: c[1], display: c[2], align: c[3] || 'l', format: c[4], width: c[5], filter: !!c[6], badge: c[7] || null, action: c[8] || null, total: c[9] || null, group: !!c[10]}; });
		}
		var ST0 = '', COLS0 = '';
		function stDirty() { return !!ST && JSON.stringify(ST) !== ST0; }
		function colsDirty() { return !!COLS && JSON.stringify(COLS) !== COLS0; }
		// kept open (0.8.931): a pinned pane opens again after the reload of a save, on the next page and on other lists
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
			return fetch(N.base + '?p=builderapi', {method: 'POST', credentials: 'same-origin',
				headers: {'Content-Type': 'application/json', 'X-CSRF-Token': N.csrf, 'Accept': 'application/json'},
				body: JSON.stringify($.extend({action: action || 'form.save', form_id: N.form.id}, N.draft ? {draft_id: N.draft.id, rev: N.draft.rev} : {}, data))
			}).then(function (r) { return r.json().catch(function () { return {ok: false, error: 'HTTP ' + r.status}; }); }).then(function (j) {
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
			pane.remove(); pane = null; cm = null; ST = null; COLS = null; OPEN = {};
			['sql', 'php', 'js'].forEach(function (k) { CODE[k].cm = null; });
			document.documentElement.classList.remove('nuBsqOpen');
			btn.focus();
		}
		function save() {
			if (!pane) return;
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
				if (ST.dcols !== o.dcols || ST.dsub !== o.dsub) fields.details = ST.dcols || ST.dsub ? {cols: ST.dcols, sub: ST.dsub} : null;
			}
			var steps = [];
			if (colsDirty()) steps.push(function () { return post({columns: COLS}, 'browse.save'); });
			if (Object.keys(fields).length || php) steps.push(function () { return post(php ? {fields: fields, php: php} : {fields: fields}); });
			if (!steps.length) { close(true); return; }
			sb.disabled = true; err.hidden = true;
			steps.reduce(function (p, f) { return p.then(f); }, Promise.resolve()).then(function () { B.sql = v; CODE.sql.orig = v; CODE.php.orig = vp; CODE.js.orig = vj; ST0 = JSON.stringify(ST); COLS0 = JSON.stringify(COLS); close(true); window.location.reload(); })
				.catch(function (e) { sb.disabled = false; err.textContent = e.message; err.hidden = false; });
		}
		function size() {
			if (!pane) return;
			['sql', 'php', 'js'].forEach(function (k) {
				var b = pane.querySelector('.nuBsqBody[data-code="' + k + '"]');
				if (CODE[k].cm && b && b.offsetParent) CODE[k].cm.setSize('100%', Math.max(120, b.clientHeight) + 'px');
			});
		}
		// a dot on the PHP / JavaScript tab while it holds code (0.8.1133) – as the Code list of design mode
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
				if (k === 'sql') cm = c.cm; else c.cm.on('change', codeMarks);
				if (window.NuCodeTools) NuCodeTools.attach(c.cm, pane.querySelector('.nuBsqTools[data-code="' + k + '"]'), {lang: c.lang, db: N.db_type});
				size();
				if (focus) c.cm.focus();
			});
		}
		// what a column offers beyond the four fields of its row (0.8.1108): format, filter, badge and what a click on its cells does
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
			var txt = function (val, fn, ph) { var x = mk('input', {type: 'text', 'class': 'form-control form-control-sm nuBsqMono', value: val, placeholder: ph || null}); x.addEventListener('input', function () { fn(x.value); }); return x; };
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
			var pick = function (list, types) { return [['', T.please_select]].concat((list || []).filter(function (f) { return !types || types.indexOf(f[2]) >= 0; }).map(function (f) { return [f[0], f[1]]; })); };
			var rules = Array.isArray(c.badge) ? c.badge.length : 0;
			var kids = [mk('div', {'class': 'nuBsqGrid'}, [
				fld('format', T.builder_col_format, sel(fmtOptions(c.format), c.format, function (v) { c.format = v; })),
				mk('div', {'class': 'nuBsqMoreChecks'}, [chk('filter', T.builder_col_filter, c.filter, function (on) { c.filter = on; }),
					chk('badge', T.ds_col_badge, !!c.badge, function (on) { c.badge = on ? (c.badge || true) : null; }, T.ds_col_badge_tip),
					rules ? mk('div', {'class': 'small text-body-secondary nuBsqBadgeRules', text: String(T.bsq_badge_rules || '').replace('%d', String(rules))}) : null]),
				fld('total', T.ds_col_total, sel([['', T.ds_tot_none], ['sum', T.ds_tot_sum], ['avg', T.ds_tot_avg], ['min', T.ds_tot_min], ['max', T.ds_tot_max], ['count', T.ds_tot_count]], c.total || '', function (v) { c.total = v || null; }), T.ds_col_total_tip),
				mk('div', {'class': 'nuBsqMoreChecks'}, [chk('group', T.ds_col_group, !!c.group, function (on) { COLS.forEach(function (x) { x.group = false; }); c.group = on; drawCols(); }, T.ds_col_group_tip)])])];
			var g = [fld('action_t', T.ds_col_action, sel([['', T.ds_act_none], ['form', T.ds_act_form], ['list', T.ds_act_list], ['proc', T.ds_act_proc], ['report', T.ds_act_report], ['js', T.ds_act_js]],
				a.t || '', function (v) { act({t: v}, true); }), T.ds_col_action_tip)];
			if (a.t === 'form') g.push(fld('action_id', T.ds_act_target_form, sel(pick(tg.forms, ['edit', 'browseedit']), a.id || '', function (v) { act({id: v}, true); }, true)));
			if (a.t === 'list') g.push(fld('action_id', T.ds_act_target_list, sel(pick(tg.forms, ['browse', 'browseedit']), a.id || '', function (v) { act({id: v}, true); }, true)));
			if (a.t === 'proc') g.push(fld('action_id', T.builder_run_procedure, sel(pick(tg.procs), a.id || '', function (v) { act({id: v}, true); }, true)));
			if (a.t === 'report') g.push(fld('action_id', T.builder_run_report, sel(pick(tg.reports), a.id || '', function (v) { act({id: v}, true); }, true)));
			if (a.t === 'js') g.push(fld('action_id', T.ds_act_fn, txt(a.id || '', function (v) { act({id: v.trim()}); }, 'openReadings'), T.ds_act_fn_tip));
			if (a.t === 'form') g.push(fld('action_rec', T.ds_act_rec, sel([['row', T.ds_act_rec_row], ['value', T.ds_act_rec_value], ['new', T.ds_act_rec_new]], a.rec || 'row', function (v) { act({rec: v}); })));
			if (a.t === 'form' || a.t === 'list') g.push(fld('action_open', T.ds_act_open, sel([['0', T.ds_act_open_same], ['2', T.ds_act_open_tab], ['3', T.ds_act_open_popup]], a.open || '0', function (v) { act({open: v}); })));
			if (a.t && a.t !== 'js') g.push(fld('action_pass', T.ds_act_pass, txt(a.pass || 'PARENT_ID', function (v) { act({pass: v.trim()}); }), T.ds_act_pass_tip));
			if (a.t) g.push(fld('action_look', T.ds_act_look, sel([['link', T.ds_act_look_link], ['button', T.ds_act_look_button], ['icon', T.ds_act_look_icon]], a.look || 'link', function (v) { act({look: v}, true); })));
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
			Object.keys(attrs || {}).forEach(function (k) { if (attrs[k] === null || attrs[k] === undefined || attrs[k] === false) return; if (k === 'text') e.textContent = attrs[k]; else e.setAttribute(k, attrs[k] === true ? '' : attrs[k]); });
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
				return mk('div', {'class': 'nuBsqField'}, [mk('label', {'class': 'form-label small mb-1', 'for': id, text: txt}), tip ? mk('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', title: tip, 'aria-label': tip}, [ico('bi-info-circle')]) : null, input]);
			};
			var num = function (k) { var i = mk('input', {type: 'number', min: '0', 'class': 'form-control form-control-sm', 'data-bsq': k, value: ST[k]}); i.addEventListener('input', function () { ST[k] = i.value.trim(); }); return i; };
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
				try { localStorage.setItem('nunext-bsq-settings', setOpen ? '1' : '0'); } catch (e) { /* private mode */ }
			});
			setShow(setOpen);
			box.appendChild(mk('h3', {'class': 'nuBsqSub'}, [setBtn]));
			box.appendChild(setBody);
			setBody.appendChild(mk('div', {'class': 'nuBsqGrid'}, [lab(T.builder_rows_per_page, num('rows')), lab(T.ds_list_rowh, num('rowh'), T.ds_list_rowh_tip),
				lab(T.ds_list_target, sel('target', ['0', '1', '2', '3'].map(function (x) { return [x, T['ds_list_target_' + x]]; }))),
				lab(T.builder_redirect, sel('redirect', [['', T.please_select]].concat(L.forms || [])))]));
			// lists of forms are searchable (0.8.1079): Tom Select, its list in <body> above the pane
			var rs = box.querySelector('[data-bsq=redirect]');
			if (rs && window.TomSelect) new TomSelect(rs, {maxOptions: null, allowEmptyOption: true, create: false, dropdownParent: 'body', plugins: ['dropdown_input'],
				onItemAdd: function () { this.close(); this.blur(); }});
			setBody.appendChild(mk('div', {'class': 'nuBsqChecks'}, [chk('multi', T.ds_list_multi), chk('autofit', T.ds_list_autofit)]));
			box.appendChild(mk('h3', {'class': 'nuBsqSub', text: T.builder_sec_columns}));
			var rowDetails = function () {                                       // below the columns: what the ▸ of a row shows
				box.appendChild(mk('h3', {'class': 'nuBsqSub', text: T.ds_rd_title}));
				var dc = chk('dcols', T.ds_rd_cols);
				dc.appendChild(mk('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', title: T.ds_rd_cols_tip, 'aria-label': T.ds_rd_cols_tip}, [ico('bi-info-circle')]));
				box.appendChild(mk('div', {'class': 'nuBsqGrid'}, [dc, lab(T.ds_rd_sub, sel('dsub', [['', T.ds_rd_none]].concat(L.subs || [])), T.ds_rd_sub_tip)]));
				if (L.access) box.appendChild(mk('p', {'class': 'small mt-3 mb-0'}, [mk('a', {href: L.access, target: '_blank', rel: 'noopener', id: 'nuBsqAccess'}, [ico('bi-shield-lock me-1'), T.bsq_col_access])]));
			};
			var tbody = mk('tbody');
			var head = mk('tr', {}, ['#', T.builder_col_title, T.builder_col_display, T.builder_col_width, T.builder_col_align, ''].map(function (x) { return mk('th', {scope: 'col', text: x}); }));
			var marks = function (c) { return !!(c.format || c.filter || c.badge || c.action || c.total || c.group); };
			box.appendChild(mk('table', {'class': 'table table-sm align-middle nuBsqColTable', id: 'nuBsqColTable'}, [mk('thead', {}, [head]), tbody]));
			COLS.forEach(function (c, i) {
				var off = +c.width === 0;
				var title = mk('input', {type: 'text', 'class': 'form-control form-control-sm', value: c.title, 'aria-label': T.builder_col_title, 'data-lc': 'title'});
				var disp = mk('input', {type: 'text', 'class': 'form-control form-control-sm nuBsqMono', value: c.display, list: 'nuBsqColNames', 'aria-label': T.builder_col_display, 'data-lc': 'display'});
				var width = mk('input', {type: 'number', min: '1', 'class': 'form-control form-control-sm', value: off ? '' : String(c.width), disabled: off, 'aria-label': T.builder_col_width, 'data-lc': 'width'});
				var align = mk('select', {'class': 'form-select form-select-sm', 'aria-label': T.builder_col_align, 'data-lc': 'align'},
					['l', 'c', 'r'].map(function (a) { return mk('option', {value: a, text: T[{l: 'om_left', c: 'om_center', r: 'om_right'}[a]], selected: c.align === a}); }));
				title.addEventListener('input', function () { c.title = title.value; });
				disp.addEventListener('input', function () { c.display = disp.value.trim(); });
				width.addEventListener('input', function () { c.width = Math.max(1, parseInt(width.value, 10) || 100); });
				align.addEventListener('change', function () { c.align = align.value; });
				var b = function (ic, tip, cls, fn, dis) { var x = mk('button', {type: 'button', 'class': 'btn btn-sm btn-link p-0 ' + (cls || 'text-body-secondary'), title: tip, 'aria-label': tip, disabled: dis, 'data-act': ic}, [ico(ic)]); x.addEventListener('click', fn); return x; };
				var move = function (d) { var x = COLS.splice(i, 1)[0]; COLS.splice(i + d, 0, x); OPEN = {}; drawCols(); };
				tbody.appendChild(mk('tr', {'class': 'nuBsqCol' + (off ? ' nuBsqColOff' : ''), 'data-col': c.id || ('new' + i)}, [
					mk('td', {'class': 'nuBsqColNo'}, [mk('span', {'class': 'nuBsqGrip', draggable: 'true', 'data-grip': String(i), title: T.ds_col_drag}, [ico('bi-grip-vertical'), String(i + 1)])]), mk('td', {}, [title]), mk('td', {}, [disp]), mk('td', {'class': 'nuBsqColW'}, [width]), mk('td', {'class': 'nuBsqColA'}, [align]),
					mk('td', {'class': 'nuBsqColBtns'}, [
						b(OPEN[i] ? 'bi-chevron-up' : 'bi-sliders', T.bsq_col_more, marks(c) ? 'text-primary nuBsqMoreBtn' : 'text-body-secondary nuBsqMoreBtn', function () { OPEN[i] = !OPEN[i]; drawCols(); }),
						b(off ? 'bi-eye-slash' : 'bi-eye', off ? T.ds_col_show : T.ds_col_hidden, '', function () { c.width = off ? 100 : 0; drawCols(); }),
						b('bi-arrow-up', T.ds_col_left, '', function () { move(-1); }, i === 0),
						b('bi-arrow-down', T.ds_col_right, '', function () { move(1); }, i === COLS.length - 1),
						b('bi-trash', T.delete, 'text-danger', function () { COLS.splice(i, 1); OPEN = {}; drawCols(); })])]));
				if (OPEN[i]) tbody.appendChild(mk('tr', {'class': 'nuBsqMoreRow', 'data-more': c.id || ('new' + i)}, [mk('td'), mk('td', {colspan: '5'}, [colMore(c, i)])]));
			});
			if (!COLS.length) tbody.appendChild(mk('tr', {}, [mk('td', {colspan: '6', 'class': 'small text-body-secondary', text: T.ds_list_nocols})]));
			// drag by the number (0.8.946): drop above / below the row under the pointer
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
		function showTab(k, focus) {
			if (!pane) return;
			pane.querySelectorAll('.nuBsqTab').forEach(function (b) { var on = b.getAttribute('data-tab') === k; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; });
			pane.querySelectorAll('.nuBsqBody, .nuBsqTools').forEach(function (x) { x.hidden = x.getAttribute('data-code') !== k; });
			pane.querySelector('.nuBsqCols').hidden = k !== 'cols';
			var hint = pane.querySelector('.nuBsqHint');
			hint.hidden = k === 'cols';
			try { localStorage.setItem(TAB, k); } catch (e) { /* no storage */ }
			if (CODE[k]) { codeOpen(k, focus === true); size(); }
		}
		// width by the splitter on the left edge (0.8.947): stored per browser, 360 px … 90 % of the window, double-click = default
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
			if (tab) { try { localStorage.setItem(TAB, tab); } catch (e) { /* no storage */ } }
			if (pane) { if (tab) showTab(tab, true); if (!tab || tab === 'cols') { var cur = lastTab(); if (CODE[cur] && CODE[cur].cm) CODE[cur].cm.focus(); else pane.querySelector('.nuBsqTab.active').focus(); } return; }
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
				+ '<div class="alert alert-danger py-1 px-2 m-2 mb-0 small nuBsqErr" role="alert" hidden></div><div class="nuBsqTools" data-code="sql"></div><div class="nuBsqBody" data-code="sql" role="tabpanel" aria-labelledby="nuBsqTabSql"><textarea></textarea></div>'
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
			pane.querySelector('.nuBsqSave').innerHTML = '<i class="bi bi-floppy me-1" aria-hidden="true"></i>';
			pane.querySelector('.nuBsqSave').appendChild(document.createTextNode(T.save));
			document.body.appendChild(pane);
			document.documentElement.classList.add('nuBsqOpen');
			splitter();
			drawCols();
			var first = lastTab();
			showTab(first, auto !== true);                                   // a pane opened by itself leaves the focus on the list
			pane.querySelector('.nuBsqClose').addEventListener('click', function () { close(); });
			pane.querySelector('.nuBsqCancel').addEventListener('click', function () { close(); });
			pane.querySelector('.nuBsqSave').addEventListener('click', save);
			pane.addEventListener('keydown', function (e) {
				if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); e.stopPropagation(); save(); }
				else if (e.key === 'Escape' && !e.defaultPrevented) { e.preventDefault(); close(); }
			});
			if (auto !== true && first === 'cols') { var f = pane.querySelector('.nuBsqTab.active'); if (f) f.focus(); }
		}
		btn.addEventListener('click', function () { open(false, 'sql'); });
		var colsBtn = document.getElementById('nuBrowseColsEdit');                  // 0.8.943: Edit columns… = the pane on its Columns tab
		if (colsBtn) colsBtn.addEventListener('click', function () { open(false, 'cols'); });
		[['nuBrowsePhpEdit', 'php'], ['nuBrowseJsEdit', 'js']].forEach(function (x) {   // 0.8.1108: PHP Before Browse and the list's JavaScript
			var e = document.getElementById(x[0]);
			if (e) e.addEventListener('click', function () { open(false, x[1]); });
		});
		N.browseSqlOpen = function () { open(); };                         // Ctrl+Shift+B and the ⋮ entry Browse SQL (0.8.932)
		$('<span class="ms-auto ps-3 small text-body-secondary nuBsqKey"></span>').text(/Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌃⇧B' : 'Ctrl+Shift+B').appendTo(btn);
		if (pinned()) open(true);
		window.addEventListener('resize', size);
		window.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
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
			if (window.NuCodeTools) NuCodeTools.attach(cm, m.find('.nuCodeToolsHost')[0], {lang: L, db: N.db_type, readOnly: ro, fullscreen: fullscreen, scope: /^set_(header|style)$/.test(el.id || '') ? 1 : undefined});
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

	// inline code editor (the owner's wish, 0.8.899): code snippets' Source code is edited in place with CodeMirror and its toolbar
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

	// =================================================================== WYSIWYG editor (nuBuilder "editor" objects, TinyMCE)
	// TinyMCE is not bundled: a library pack (Update → Libraries, vendor/packs/tinymce-<version>/); nuNext.tinymceBase = its folder.
	function tiny() { return window.tinymce || null; }

	var tinyArgs = {};                                        // arguments of the last nuInitTinyMCE per field – the theme switch starts it again (0.8.1041)
	api('nuInitTinyMCE', function (id, options, mobile, toolbar, toolbarGroups, menubar, contextmenu, quickbars) {
		var ta = g(id), box = g(id + '_container');
		if (!ta || !box) return;
		tinyArgs[id] = Array.prototype.slice.call(arguments);
		if (!tiny()) {
			$(box).hide(); $(ta).show().addClass('nuEditorFallback');
			if (!warned.tinymce) { warned.tinymce = true; console.warn('nuBuilder Next: TinyMCE not found – install its library pack (Update → Libraries)'); }
			return;
		}
		var plugins = typeof options === 'string' ? options : null;
		if (plugins) options = undefined;
		var ro = ta.readOnly || ta.getAttribute('data-nu-readonly') === '1';
		var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
		$(ta).hide();
		// height (0.8.237/0.8.239): the designed height; an editor that is the lowest object of its tab takes the free height of the
		// window instead (below it only the form's legend, which stays visible), 250–800 px – e.g. the notes form
		var wrap = box.closest('.nuWrap'), pane = box.closest('.nuTabPane') || document;
		var prect = box.parentNode.getBoundingClientRect();
		// designed height: the editor's own box, otherwise the space its wrapper reserves below the editor's top (absolute layout)
		var inWrap = wrap ? Math.floor(wrap.getBoundingClientRect().bottom - prect.top) : 0;
		var edH = Math.max(200, box.parentNode.clientHeight || inWrap || ta.offsetHeight || 300);
		if (wrap && wrap.offsetParent !== null) {
			var rect = wrap.getBoundingClientRect(), below = false;
			pane.querySelectorAll('.nuWrap').forEach(function (w) {
				if (w !== wrap && !wrap.contains(w) && w.offsetParent !== null && w.getBoundingClientRect().top >= rect.bottom - 2) below = true;
			});
			// everything that follows the editor on the page (padding of the tab, the page's bottom margin)
			// must stay visible too: free = window height − editor top − that rest
			var main = document.querySelector('main') || document.body;
			var rest = Math.max(0, main.getBoundingClientRect().bottom - Math.max(rect.bottom, prect.top + edH));
			var free = Math.floor(window.innerHeight - prect.top - rest - 8);
			if (!below) {
				// fits the window: as high as the free space allows (also smaller than designed, so the legend stays visible), 250–800 px
				edH = Math.max(250, Math.min(800, free));
				box.parentNode.style.height = edH + 'px';
				var needWrap = Math.ceil(prect.top - rect.top + edH);            // the wrapper ends where the editor ends
				var delta = needWrap - wrap.offsetHeight;
				if (wrap.style.height) wrap.style.height = needWrap + 'px';
				var area = wrap.offsetParent;                                    // absolute layout: the area of the tab follows
				if (delta && area && area !== document.body && area.style.height) area.style.height = Math.max(0, area.offsetHeight + delta) + 'px';
			}
		}
		var cfg = {
			target: box,
			base_url: N.tinymceBase || 'assets/vendor/tinymce', suffix: '.min', license_key: 'gpl', promotion: false, branding: false,
			plugins: plugins || 'code preview searchreplace autolink autosave directionality visualblocks visualchars fullscreen image link media codesample table charmap pagebreak nonbreaking anchor insertdatetime advlist lists help emoticons',
			toolbar: toolbar === 'minimal'
				? 'undo redo | bold italic underline strikethrough | link | bullist numlist | blocks | alignleft aligncenter alignright | forecolor backcolor | fullscreen'
				: (toolbar || 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor removeformat | alignleft aligncenter alignright alignjustify | outdent indent | numlist bullist | link image table charmap emoticons | fullscreen preview code'),
			toolbar_groups: toolbarGroups || {}, menubar: menubar || 'file edit view insert format tools table help', contextmenu: contextmenu || 'link image table',
			mobile: mobile || {menubar: false}, toolbar_mode: 'sliding', resize: true, elementpath: false, image_advtab: true,
			height: edH, width: '100%',
			skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default', content_style: 'p { margin: 0; }',
			autosave_ask_before_unload: false, readonly: ro, disabled: ro,
			link_default_target: '_blank',                                    // new links open in a new tab (0.8.699; form code can override it with options)
			setup: function (editor) {
				editor.addShortcut('ctrl+shift+S', 'Save', function () { syncEditors(); nuSaveAction(); });
				editor.on('init', function (e) {
					editor.setContent(ta.value);
					if (window.nuTinyMCEOnInit) { try { window.nuTinyMCEOnInit(e, editor, id); } catch (x) { reportError(x.message, 'nuTinyMCEOnInit'); } }
				});
				editor.on('SetContent', function (e) { if (window.nuTinyMCEOnSetContent) window.nuTinyMCEOnSetContent(e, editor, id); });
				editor.on('input change undo redo', function () {
					var html = editor.getContent();
					if (html !== ta.value) { ta.value = html; markEdited(ta); }
				});
			}
		};
		if (options && typeof options === 'object') $.extend(cfg, options);
		var old = tiny().get(box.id);
		if (old) old.remove();
		tiny().init(cfg);
	});

	// light / dark switch (0.8.1041): TinyMCE cannot change its skin while it runs – each editor is started again with the other skin;
	// the field holds its HTML already (input / change handler) – copied only when the editor still has unsent changes, because TinyMCE's own
	// cleaning (<b> → <strong>) would otherwise mark an untouched record as edited; the new editor takes the field's HTML
	new MutationObserver(function () {
		if (!tiny()) return;
		Object.keys(tinyArgs).forEach(function (id) {
			var ed = tiny().get(id + '_container'), ta = g(id);
			if (!ed) return;
			if (ta && ed.isDirty()) { var html = ed.getContent(); if (html !== ta.value) { ta.value = html; markEdited(ta); } }
			window.nuInitTinyMCE.apply(null, tinyArgs[id]);
		});
	}).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});

	/** Copies the editors' HTML into their fields (nuBuilder nuSaveEditor). */
	function syncEditors() {
		if (!tiny()) return;
		$('.nuTinyMCE').each(function () {
			var ed = tiny().get(this.id), ta = g(this.id.slice(0, -10));
			if (!ed || !ta) return;
			var html = ed.getContent();
			if (html !== ta.value) { ta.value = html; markEdited(ta); }
		});
	}
	api('nuSaveEditor', syncEditors);
	// nuBuilder Forte's change handler of its TinyMCE set-up (0.8.950) – form code with a copied nuInitTinyMCE calls it on every change:
	// copies the editor's HTML into its field (editor id = field id + "_container") and marks the record as edited
	api('nuTinyMCEOnChangeHandler', function (editor) {
		var id = editor && editor.id ? String(editor.id) : '', ta = /_container$/.test(id) ? g(id.slice(0, -10)) : null;
		if (ta && typeof editor.getContent === 'function') { var html = editor.getContent(); if (html !== ta.value) { ta.value = html; markEdited(ta); return; } }
		nuHasBeenEdited();
	});
	api('nuGetTinyMCEEditor', function (id) { return tiny() ? tiny().get(id + '_container') : null; });
	api('nuTinyMCESetContent', function (id, html) { var e = nuGetTinyMCEEditor(id); if (e) e.setContent(html); else if (g(id)) g(id).value = html; });
	api('nuTinyMCEGetContent', function (id) { var e = nuGetTinyMCEEditor(id); return e ? e.getContent() : (g(id) ? g(id).value : ''); });
	api('nuTinyMCESetBounds', function (id, left, top, width, height) {
		var el = g(id + '_parent_container');
		if (!el) return;
		if (left) el.style.left = left + 'px';
		if (top) el.style.top = top + 'px';
		if (width) el.style.width = width + 'px';
		if (height) el.style.height = height + 'px';
	});
	api('nuIsIframe', function () { return !!N.iframe; });
	api('nuIsMobile', function () { return window.matchMedia('(max-width: 767.98px)').matches; });
	api('nuUseMobileView', function () { return false; });
	if (!$.fn.nuOnEnterKey) $.fn.nuOnEnterKey = function (fn) { return this.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); fn.call(this, e); } }); };
	if (!String.prototype.nuJustNumbers) String.prototype.nuJustNumbers = function () { return this.replace(/[^0-9]/g, ''); };
	// nuBuilder Forte: size small | medium | large | a CSS size; appendToEnd puts the icon after the text
	api('nuAttachFontAwesome', function (id, cls, size, appendToEnd) {
		var t = $(typeof id === 'string' ? '#' + id : id);
		if (!t.length) return;
		var text = t.text().trim(), i = $('<i>').addClass(cls);
		var fs = size === 'small' ? 'small' : size === 'large' ? 'x-large' : (!size || size === 'medium' ? '' : size);
		if (fs) i.css('font-size', fs);
		if (text) i.css(appendToEnd ? 'margin-left' : 'margin-right', '3px'); else t.css('padding', '0 9px');
		if (appendToEnd) t.append(i); else t.prepend(i);
	});


	// =================================================================== nuBuilder API, part 2 (0.7.2)
	// ---- user details (blank for the globeadmin, like nuBuilder)
	api('nuUserId', function () { return N.user.id || ''; });
	api('nuUserLogin', function () { return N.user.login || ''; });
	// permission items (zzzzsys_permission_item.pme_ident) of the user; the globeadmin has all unless userOnly
	api('nuUserPermissions', function () { return (N.user.permissions || []).slice(); });
	api('nuUserHasPermission', function (item, userOnly) {
		if (userOnly !== true && N.user.globeadmin) return true;
		return (N.user.permissions || []).indexOf(String(item)) !== -1;
	});
	api('nuAccessLevelId', function () { return N.user.access_level_id || ''; });
	api('nuUserCode', function () { return N.user.code || ''; });
	api('nuUserPosition', function () { return N.user.position || ''; });
	api('nuUserDepartment', function () { return N.user.department || ''; });
	api('nuUserTeam', function () { return N.user.team || ''; });
	api('nuUserFirstName', function () { return N.user.first_name || ''; });
	api('nuUserLastName', function () { return N.user.last_name || ''; });
	api('nuUserAdditional1', function () { return N.user.additional1 || ''; });
	api('nuUserAdditional2', function () { return N.user.additional2 || ''; });

	// ---- tabs
	function tabIndexByTitle(title) {
		var want = [String(title), nuTranslate(String(title))].map(function (x) { return x.trim().toLowerCase(); });
		var found = -1;
		$('.nuTab').each(function () {
			if (found < 0 && want.indexOf($(this).text().trim().toLowerCase()) >= 0) found = +this.getAttribute('data-nu-tab');
		});
		return found;
	}
	function selectedTab() { return $('.nuTab.active')[0] || null; }
	api('nuOpenTab', function (i) { nuSelectTab(+i); });
	// nuBuilder Forte returns the number as text ("0", "1" …)
	api('nuSelectedTabNumber', function () { var t = selectedTab(); return t ? String(t.getAttribute('data-nu-tab')) : null; });
	api('nuSelectedTabId', function () { var t = selectedTab(); return t ? t.getAttribute('data-nu-tab-id') : null; });
	api('nuSelectedTabTitle', function () { var t = selectedTab(); return t ? t.innerHTML : null; });
	api('nuShowTabByTitle', function (title, visible) {
		var i = tabIndexByTitle(title);
		if (i >= 0) { if (visible === false) nuHideTab(i); else nuShowTab(i); }
	});
	api('nuHideTabByTitle', function (title) { nuShowTabByTitle(title, false); });
	api('nuHideTabs', function () { Array.prototype.forEach.call(arguments, function (a) { if (a === parseInt(a, 10)) nuHideTab(a); else nuHideTabByTitle(a); }); });
	api('nuRemoveTabs', function () {
		Array.prototype.forEach.call(arguments, function (i) {
			var t = g('nuTab' + i);
			if (!t) return;
			var pane = g('nuTabPane' + i);
			$(t).closest('li').remove();
			if (pane) $(pane).find('[data-nu-field]').addClass('nuRemovedTab');   // values are kept, the tab is gone
			if (pane) $(pane).hide();
		});
	});
	api('nuTabSetMarker', function (tabId, fieldIdOrFlag, className) {
		if (!tabId || fieldIdOrFlag === null || fieldIdOrFlag === undefined) return;
		var tab = document.querySelector('.nuTab[data-nu-tab-id="' + String(tabId).replace(/"/g, '') + '"]');
		if (!tab) return;
		var on = typeof fieldIdOrFlag === 'boolean' ? fieldIdOrFlag : String(nuGetValue(fieldIdOrFlag) || '').trim() !== '';
		String(className || 'nuTabMarker').split(/\s+/).forEach(function (c) { if (c) tab.classList.toggle(c, on); });
	});

	// ---- state
	api('nuIsClone', function () { return !!N.cloning; });
	api('nuIsNewRecord', function () { return !!N.is_new && !N.cloning; });
	window.nuSubformRow = -1;
	$(document).on('focusin', '[data-nu-prefix]', function () {
		var p = this.getAttribute('data-nu-prefix') || '';
		if (p) window.nuSubformRow = Number(p.slice(-3));
	});
	api('nuCurrentRow', function () { return window.nuSubformRow; });
	// nuBuilder: $('#' + prefix + id).val() – the value as shown in the field (formatted numbers stay formatted, 0.8.468)
	api('nuSubformValue', function (el, field) {
		var p = $(el).attr('data-nu-prefix') || $(el).closest('[data-nu-prefix]').attr('data-nu-prefix') || '';
		return $('#' + p + field).val();
	});

	/**
	 * nuBuilder 4.7+: the clicked browse cell (or another cell of its row – column index or the column's id, data-nu-column-id)
	 * as {pk, row, column, colId, html, value, $cell}. Takes the cell element or the click event (nuSelectBrowse(event, element)).
	 */
	api('nuBrowseRow', function (elOrEvent, columnNumberOrId) {
		var $invoked = (elOrEvent && elOrEvent.target) ? $(elOrEvent.currentTarget || elOrEvent.target) : $(elOrEvent);
		if (!$invoked.is('[data-nu-row]')) $invoked = $invoked.closest('[data-nu-row]');
		var rowAttr = $invoked.attr('data-nu-row');
		var row = rowAttr != null ? parseInt(rowAttr, 10) : null;
		var $cell = $invoked;
		if (columnNumberOrId != null && row !== null) {
			var $byPk = $('.nuCell[data-nu-row="' + row + '"][data-nu-column-id="' + String(columnNumberOrId).replace(/["\\]/g, '') + '"]');
			$cell = $byPk.length ? $byPk : $('#nucell_' + row + '_' + columnNumberOrId);
		}
		return {
			pk: $cell.attr('data-nu-primary-key'), row: row, column: parseInt($cell.attr('data-nu-column'), 10), colId: $cell.attr('data-nu-column-id'),
			html: $cell.html(), value: $cell.text(), $cell: $cell
		};
	});

	// ---- dates and ids
	function ymd(d) { return d.getFullYear() + '-' + nuPad2(d.getMonth() + 1) + '-' + nuPad2(d.getDate()); }
	api('nuCurrentDate', function (format) {
		var s = ymd(new Date());
		return format === undefined ? s : nuFORM.addFormatting(s, /^D\|/.test(format) ? format : 'D|' + format);
	});
	api('nuSetDateValue', function (id, date) {
		var el = g(id);
		if (!el) return false;
		var d = date instanceof Date ? date : (date === undefined || date === null || date === '' ? new Date() : new Date(date));
		if (isNaN(d)) d = new Date();
		var f = el.getAttribute('data-nu-format');
		$(el).val(f ? nuFORM.addFormatting(ymd(d), f) : ymd(d));
		markEdited(el);
		$(el).trigger('change');
		return true;
	});
	window.nuSuffix = window.nuSuffix || 0;
	api('nuSetSuffix', function (a) {
		if (arguments.length === 1) window.nuSuffix = a;
		window.nuSuffix = window.nuSuffix >= 9999 ? 0 : window.nuSuffix + 1;
	});
	api('nuID', function () {
		nuSetSuffix();
		window.nuUniqueID = 'c' + String(Date.now());
		return window.nuUniqueID + String(window.nuSuffix);
	});
	api('nuPasteText', function (id, callback) {
		if (!navigator.clipboard || !navigator.clipboard.readText) return;
		navigator.clipboard.readText().then(function (text) {
			nuSetValue(id, text);
			if (typeof callback === 'function') callback(id, text);
			else if (callback && typeof window[callback] === 'function') window[callback](id, text);
		});
	});

	// ---- string / date helpers nuBuilder adds to String and Date
	function proto(obj, name, fn) { if (!obj.prototype[name]) Object.defineProperty(obj.prototype, name, {value: fn, writable: true, configurable: true}); }
	proto(String, 'nuStartsWith', function (s, ignoreCase) { return ignoreCase ? this.toLowerCase().startsWith(String(s).toLowerCase()) : this.startsWith(s); });
	proto(String, 'nuEndsWith', function (s, ignoreCase) { return ignoreCase ? this.toLowerCase().endsWith(String(s).toLowerCase()) : this.endsWith(s); });
	proto(String, 'nuReplaceAll', function (a, b, ignoreCase) { return this.replace(new RegExp(String(a).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g' + (ignoreCase ? 'i' : '')), b); });
	proto(String, 'nuStringToArray', function (sep, trim) { var r = this.split(sep === undefined ? ',' : sep); return trim === false ? r : r.map(function (x) { return x.trim(); }); });
	proto(String, 'nuLeftTrim', function () { return this.replace(/^\s+/, ''); });
	proto(String, 'nuRightTrim', function () { return this.replace(/\s+$/, ''); });
	proto(String, 'nuReplaceNonBreakingSpaces', function (w) { return this.replace(/\xA0/g, w === undefined ? ' ' : w); });
	proto(String, 'nuWithoutNumbers', function () { return this.replace(/[0-9]/g, ''); });
	proto(String, 'nuInsertString', function (i, s) { return i > 0 ? this.substring(0, i) + s + this.substring(i) : s + this; });
	proto(String, 'nuIsEmpty', function () { return this.length === 0; });
	proto(String, 'nuStripBoundaryTags', function (open, close) {
		var r = this.trim();
		if (r.startsWith(open)) r = r.slice(open.length);
		if (r.endsWith(close)) r = r.slice(0, -close.length);
		return r;
	});
	proto(String, 'nuFormat', function () {
		var a = arguments.length === 1 && arguments[0] !== null && typeof arguments[0] === 'object' ? arguments[0] : arguments;
		return this.replace(/{([^}]*)}/g, function (m, k) { return a[k] !== undefined ? a[k] : m; });
	});
	proto(Date, 'nuWithoutTime', function () { var d = new Date(this); d.setHours(0, 0, 0, 0); return d; });
	proto(Date, 'nuAddDays', function (n) { var d = new Date(this.valueOf()); d.setDate(d.getDate() + n); return d; });

	// ---- layout
	// 0.8.743 (the owner's decision): 0 = the row with Add / Back / Save …, 1 = nuBuilder Next's top bar (logo, search, user menu),
	// 2 = the tabs of the form. The top bar is only hidden, never removed (menus and the bell live in it): html.nuNoNavbar, --nu-nav-h 0
	var HOLDERS = {0: '#nuActionHolder', 2: '#nuTabHolder, #userTabs'};
	function holderNav(remove) {
		document.documentElement.classList.add('nuNoNavbar');
		document.documentElement.style.setProperty('--nu-nav-h', '0px');   // the bar's measured height is set inline (nuapp.js) and beats the class rule
		window.dispatchEvent(new Event('resize'));          // sticky rows measure again (nuapp.js)
		return remove;
	}
	api('nuHideHolders', function () { Array.prototype.forEach.call(arguments, function (a) { if (+a === 1) holderNav(false); else if (HOLDERS[a]) $(HOLDERS[a]).addClass('nuHolderHidden'); }); });
	api('nuRemoveHolders', function () { Array.prototype.forEach.call(arguments, function (a) { if (+a === 1) holderNav(true); else if (HOLDERS[a]) $(HOLDERS[a]).remove(); }); });
	api('nuSetVerticalTabs', function () { $('#nuEditForm').addClass('nuVerticalTabs'); });
	api('nuPrintEditForm', function (hideObjects, showObjects) {
		hideObjects = arr(hideObjects); showObjects = arr(showObjects);
		document.body.classList.add('nuPrintingEditForm');
		hideObjects.forEach(function (id) { nuHide(id); });
		showObjects.forEach(function (id) { nuShow(id); });
		var restore = function () {
			window.removeEventListener('afterprint', restore);
			document.body.classList.remove('nuPrintingEditForm');
			hideObjects.forEach(function (id) { nuShow(id); });
			showObjects.forEach(function (id) { nuHide(id); });
		};
		window.addEventListener('afterprint', restore);
		window.print();
	});

	// ---- images from nuBuilder Files (zzzzsys_file, by code)
	function fileUrl(code) { return N.base + '?p=file&code=' + encodeURIComponent(String(code)); }
	api('nuAttachImage', function (id, code, fit) {
		var t = g(id);
		if (!t) return;
		var img = document.createElement('img');
		img.id = 'image_' + id;
		img.className = 'nuBrowseImage nuAttachedImage';
		img.alt = '';
		if (fit !== false) img.classList.add('nuImageFit');
		img.src = fileUrl(code);
		t.innerHTML = '';
		t.appendChild(img);
	});
	api('nuAttachButtonImage', function (id, code, cssClass) {
		var t = g(id);
		if (!t) return;
		t.style.backgroundImage = 'url("' + fileUrl(code).replace(/"/g, '%22') + '")';
		t.classList.add(cssClass || 'nuButtonImage');
	});
	api('nuEmbedObject', function (json, containerId, width, height) {
		if (!json) return;
		var d;
		try { d = typeof json === 'string' ? JSON.parse(json) : json; } catch (e) { return; }
		var url = '';
		try { url = atob(d.file); } catch (e) { url = ''; }
		if (url.indexOf('data:') !== 0) url = 'data:' + d.type + ';base64,' + d.file;
		var box = g(containerId);
		if (!box) return;
		width = width === undefined ? 300 : width; height = height === undefined ? 300 : height;
		var el;
		if (/^image\//.test(d.type || '')) { el = document.createElement('img'); el.alt = d.name || ''; }
		else { el = document.createElement('embed'); el.type = d.type || ''; }
		el.src = url;
		if (width !== -1) el.width = width;
		if (height !== -1) el.height = height;
		box.innerHTML = '';
		box.appendChild(el);
	});

	// ---- browse
	// nuBrowseStyleBadge(column[, rules[, options]]) – nuBuilder Forte's badge column; rules (0.8.681, nuBuilder Next only – Forte ignores them):
	//   an object {value: colour | {bg, color, …}} = exact values, or an array of rules (the first that matches wins):
	//   {equals | contains | startsWith | endsWith: text, regex: /…/, test: fn(value, row), default: true, ignoreCase: true,
	//    bg, color, border, class, label, icon, title, badge: false}; bg / color / border '$value' = the cell's own value as colour;
	//   options {ignoreCase}. Only bg → the text colour is chosen for contrast. Text stays text; colours go through element.style.
	function badgeRuleOk(r, v, row, ic) {
		var cmp = function (x) { return ic ? String(x).toLowerCase() : String(x); }, val = cmp(v);
		if (r.default) return true;
		if (r.equals !== undefined && val !== cmp(r.equals)) return false;
		if (r.contains !== undefined && val.indexOf(cmp(r.contains)) < 0) return false;
		if (r.startsWith !== undefined && val.indexOf(cmp(r.startsWith)) !== 0) return false;
		if (r.endsWith !== undefined && (val.length < cmp(r.endsWith).length || val.slice(-cmp(r.endsWith).length) !== cmp(r.endsWith))) return false;
		if (r.regex !== undefined && !(r.regex instanceof RegExp ? r.regex : new RegExp(String(r.regex), ic ? 'i' : '')).test(String(v))) return false;
		if (typeof r.test === 'function' && !r.test(v, row)) return false;
		return r.equals !== undefined || r.contains !== undefined || r.startsWith !== undefined || r.endsWith !== undefined || r.regex !== undefined || typeof r.test === 'function';
	}
	function badgeRules(rules) {
		if (!rules) return [];
		if (Array.isArray(rules)) return rules.filter(function (r) {
			var ok = r && typeof r === 'object';
			if (!ok && N.dev_mode) console.warn('nuBrowseStyleBadge: rule skipped', r);
			return ok;
		});
		if (typeof rules === 'object') return Object.keys(rules).map(function (k) {
			var x = rules[k];
			return $.extend({equals: k}, typeof x === 'string' ? {bg: x} : (x && typeof x === 'object' ? x : {}));
		});
		return [];
	}
	api('nuBrowseStyleBadge', function (column, rules, options) {
		var list = badgeRules(rules), icAll = !!(options && options.ignoreCase);
		nuBrowseLoop(arr(column), function (cell) {
			var txt = $(cell).text().trim();
			if (!txt) return;
			var tr = cell.closest('tr'), row = [];
			if (tr) tr.querySelectorAll('div.nuCell[data-nu-column]').forEach(function (td) { row[+td.getAttribute('data-nu-column')] = td.textContent.trim(); });
			var hit = null;
			for (var i = 0; i < list.length && !hit; i++) {
				try { if (badgeRuleOk(list[i], txt, row, list[i].ignoreCase !== undefined ? !!list[i].ignoreCase : icAll)) hit = list[i]; }
				catch (e) { if (N.dev_mode) console.warn('nuBrowseStyleBadge: rule failed', list[i], e); }
			}
			if (hit && hit.badge === false) return;
			var span = $('<span class="badge rounded-pill nuBrowseBadge"></span>'), el = span[0];
			if (hit && hit.icon) span.append($('<i class="me-1" aria-hidden="true"></i>').addClass('bi ' + (/^bi-/.test(hit.icon) ? hit.icon : 'bi-' + hit.icon)));
			span.append(document.createTextNode(hit && hit.label !== undefined && hit.label !== null ? String(hit.label) : txt));
			if (hit) {
				var col = function (c) { c = c === '$value' ? txt : c; return c !== undefined && c !== null && cssRgb(String(c)) ? String(c) : ''; };
				if (hit.class) { el.classList.remove('nuBrowseBadge'); String(hit.class).split(/\s+/).filter(Boolean).forEach(function (k) { el.classList.add(k); }); }
				var bg = col(hit.bg), fg = col(hit.color), bd = col(hit.border);
				if (bg) { el.style.background = bg; el.classList.remove('nuBrowseBadge'); }
				if (fg) el.style.color = fg;
				else if (bg) { var L = luminance(cssRgb(bg)); el.style.color = 1.05 / (L + 0.05) >= (L + 0.05) / 0.066 ? '#fff' : '#212529'; }   // the better contrast
				if (bd) el.style.border = '1px solid ' + bd;
				if (hit.title) el.title = String(hit.title);
				else if (hit.label !== undefined && String(hit.label) !== txt) el.title = txt;
			}
			if (span.text() !== txt) cell.setAttribute('data-nu-raw', txt);   // the stored value, when a label replaces it (read by dev/tests/compat.js)
			$(cell).empty().append(span);
		});
	});

	// =================================================================== nuBuilder API, part 3 (Group 2)
	// ---- refresh select / display objects: the server re-runs their SQL with the current values (main form + subform row)
	function rowValues(prefix) {
		var v = {};
		if (!prefix) return v;
		$('[data-nu-prefix="' + prefix + '"][data-nu-field]').each(function () {
			if (this.type === 'button' || this.tagName === 'DIV' || this.tagName === 'BUTTON') return;
			v[this.getAttribute('data-nu-field')] = rawValue(this);
		});
		return v;
	}
	/** Elements to refresh: prefix + id; with another form id and no prefix, that field in every row of the subforms showing that form. */
	function refreshTargets(prefix, id, formId) {
		if (prefix || !formId || formId === N.form.id) { var el = g((prefix || '') + id); return el ? [el] : []; }
		var out = [];
		Object.keys(N.subforms || {}).forEach(function (sf) {
			if (N.subforms[sf].form_id !== formId) return;
			$('#' + sf + ' [data-nu-field="' + id + '"][data-nu-prefix]').each(function () { if (!this.closest('template')) out.push(this); });
		});
		return out;
	}
	function refreshCall(el, id, formId) {
		return call('refresh', {object: id, object_form: formId || N.form.id, values: mainValues(), row: rowValues(el.getAttribute('data-nu-prefix') || '')});
	}
	function refreshSelect(prefix, selectId, formId, removeBlank) {
		formId = formId || N.form.id;
		var els = refreshTargets(prefix, selectId, formId);
		if (!els.length) { callHook('nuSelectObjectRefreshed', formId, (prefix || '') + selectId, -1); return Promise.resolve(-1); }
		return Promise.all(els.map(function (el) {
			return refreshCall(el, selectId, formId).then(function (res) {
				var count = -1;
				if (res && res.ok !== false && res.found) {
					var wasSaved = !N.edited, cur = $(el).val();
					var keep = Array.isArray(cur) ? cur.map(String) : [String(cur === null ? '' : cur)];
					var frag = document.createDocumentFragment();
					var nb = el.hasAttribute('data-nu-noblank'), any = false;   // no blank item: validation No Blanks / No Duplicates (0.8.958)
					if (removeBlank !== true && !el.multiple && !nb) frag.appendChild(new Option('', ''));
					(res.options || []).forEach(function (o) {
						var opt = new Option(o[1], o[0]);
						if (keep.indexOf(String(o[0])) >= 0) { opt.selected = true; any = true; }
						frag.appendChild(opt);
					});
					el.innerHTML = '';
					el.appendChild(frag);
					if (nb && !any) el.selectedIndex = -1;
					count = (res.options || []).length;
					if (wasSaved) nuHasNotBeenEdited();
				}
				callHook('nuSelectObjectRefreshed', formId, el.id, count);
				return count;
			});
		})).then(function (r) { return r.length === 1 ? r[0] : r; });
	}
	function refreshDisplay(prefix, displayId, formId) {
		formId = formId || N.form.id;
		return Promise.all(refreshTargets(prefix, displayId, formId).map(function (el) {
			return refreshCall(el, displayId, formId).then(function (res) {
				if (!res || res.ok === false || !res.found) { nuMessage([nuTranslate('Failed to refresh the Display Object:') + ' ' + el.id]); return false; }
				var f = el.getAttribute('data-nu-format');
				var v = res.value === null || res.value === undefined ? '' : String(res.value);
				// as nuBuilder Forte (nuSetValue): the field changes – "edited", and the value is saved when the table has its column (0.8.989)
				if ('value' in el) { var nv = f ? nuFORM.addFormatting(v, f) : v; if (el.value !== nv) { el.value = nv; $(el).trigger('change'); } } else el.textContent = v;
				callHook('nuDisplayObjectRefreshed', el.id, formId);
				return true;
			});
		}));
	}
	api('nuRefreshSelectObject', function (selectId, formId, removeBlank) { return refreshSelect('', selectId, formId, removeBlank); });
	api('nuSubformRefreshSelectObject', function (prefix, selectId, formId, removeBlank) { return refreshSelect(prefix, selectId, formId, removeBlank); });
	api('nuRefreshDisplayObject', function (displayId, formId) { return refreshDisplay('', displayId, formId); });
	api('nuSubformRefreshDisplayObject', function (prefix, displayId, formId) { return refreshDisplay(prefix, displayId, formId); });

	// ---- browse: search from code, columns excluded from the search
	// the address of this list – also when code rewrote the address bar the way nuBuilder Forte does (?f=…&r=…&h=…, without p=form)
	function browseUrl() {
		var u = new URL(window.location.href);
		if (!u.searchParams.get('p') && N.form && N.mode === 'browse') {
			['h', 'r'].forEach(function (k) { u.searchParams.delete(k); });
			u.searchParams.set('p', 'form'); u.searchParams.set('f', N.form.id);
		}
		return u;
	}
	function currentNoSearch() {
		var v = browseUrl().searchParams.get('nsc') || '';
		return v === '' ? [] : v.split(',').map(Number).filter(function (n) { return !isNaN(n); });
	}
	function setSearchHidden(name, value) {
		var f = document.querySelector('form.nuBrowseSearch');
		if (!f) return;
		var i = f.querySelector('input[type=hidden][name="' + name + '"]');
		if (value === '' || value === null) { if (i) i.remove(); return; }
		if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = name; f.appendChild(i); }
		i.value = value;
	}
	function applyNoSearchMarks(list) {
		$('.nuBrowseTitle').each(function () { this.classList.toggle('nuNoSearch', list.indexOf(+this.getAttribute('data-nu-col')) >= 0); });
	}
	function applyNoSearch(list) {
		list = list.filter(function (v, i, a) { return a.indexOf(v) === i; }).sort(function (a, b) { return a - b; });
		applyNoSearchMarks(list);
		var val = list.join(','), u = browseUrl(), before = u.searchParams.get('nsc') || '';
		setSearchHidden('nsc', val);
		if (val === before) return;
		if (val) u.searchParams.set('nsc', val); else u.searchParams.delete('nsc');
		if ((u.searchParams.get('q') || '') !== '') { u.searchParams.delete('pg'); window.location.replace(u.toString()); }   // the shown result used other columns
		else history.replaceState(null, '', u.toString());
	}
	api('nuSetNoSearchColumns', function (columns) { applyNoSearch(currentNoSearch().concat(arr(columns).map(Number))); });
	api('nuSetSearchColumns', function (columns) {
		columns = arr(columns).map(Number);
		var no = [];
		$('.nuBrowseTitle').each(function () { var i = +this.getAttribute('data-nu-col'); if (columns.indexOf(i) < 0) no.push(i); });
		applyNoSearch(no);
	});
	api('nuSearchColumnsReset', function () { applyNoSearch([]); });
	// advanced search (0.8.719): the panel lives in assets/js/nu/nuadvsearch.js (browse lists only)
	api('nuNextAdvancedSearch', function (conditions, mode) { return typeof window.nuNextAdvSearch === 'function' ? window.nuNextAdvSearch(conditions, mode) : null; });
	api('nuSearchAction', function (search, filter) {
		var field = g('nuSearchField'), u = browseUrl();
		if (arguments.length > 0 && field) field.value = search === undefined || search === null ? '' : search;
		var o = {search: field ? field.value : (arguments.length > 0 ? String(search) : (u.searchParams.get('q') || '')),
			filter: arguments.length === 2 ? String(filter === null || filter === undefined ? '' : filter) : (u.searchParams.get('rf') || '')};
		var ok = true;
		['nuOnSearchActionGlobal', 'nuOnSearchAction'].forEach(function (h) { if (ok && typeof window[h] === 'function' && callHook(h, o) === false) ok = false; });
		if (!ok) return;
		if (o.search) u.searchParams.set('q', o.search); else u.searchParams.delete('q');
		if (o.filter) u.searchParams.set('rf', o.filter); else u.searchParams.delete('rf');
		u.searchParams.delete('pg');
		// properties set just before (nuSetProperty + nuSearchAction, as filter buttons / selects of nuBuilder Forte do) must reach the
		// browse SQL as hash variables: store them first and pass the new scope (was: the scope of the page as loaded)
		withScope(function (key) {
			if (key) u.searchParams.set('ps', key); else u.searchParams.delete('ps');
			browseNav(u.toString());
		});
	});
	$(document).on('submit', 'form.nuBrowseSearch', function (e) {   // the search field: nuOnSearchAction hooks may change or cancel it
		if (typeof window.nuOnSearchActionGlobal !== 'function' && typeof window.nuOnSearchAction !== 'function') {
			if (e.isDefaultPrevented() || !smoothOk()) return;                 // in place (0.8.776): the form's fields as a URL
			var qs = new URLSearchParams();
			new FormData(this).forEach(function (v, k) { if (typeof v === 'string') qs.append(k, v); });
			var to = new URL(this.getAttribute('action') || 'index.php', window.location.href);
			to.search = qs.toString();
			if (browseGo(to.toString(), true)) e.preventDefault();
			return;
		}
		e.preventDefault();
		nuSearchAction(g('nuSearchField') ? g('nuSearchField').value : '');
	});

	// ---- browse lists refreshed in place (0.8.776): search, column filters, sort titles, pages and the page size fetch the list and swap
	// only its card (no page reload, no flicker). Automatic for forms without code of their own (server flag N.browse.smooth: no form /
	// browse JavaScript, no Setup → Header or includes) and without load / search / row hooks; nuNextSmoothBrowse(true) in form code turns
	// it on anyway (the form's nuLoadBrowseGlobal / nuOnLoad then run again after each swap), nuNextSmoothBrowse(false) off. Anything
	// unusual (another form, a record, an error, a changed toolbar) loads the page the normal way.
	var SM = {on: null, ctrl: null};
	api('nuNextSmoothBrowse', function (on) { SM.on = on === undefined ? true : !!on; });
	function smoothOk() {
		if (N.mode !== 'browse' || !N.browse || !window.fetch || !window.DOMParser || document.body.classList.contains('nuPrintView')) return false;
		if (SM.on !== null) return SM.on;
		if (!N.browse.smooth) return false;
		return ['nuOnLoad', 'nuLoadBrowseGlobal', 'nuSelectBrowse', 'nuOnSearchAction', 'nuOnSearchActionGlobal', 'nuBrowseFunction'].every(function (h) {
			if (typeof window[h] !== 'function') return true;
			var src = '';
			try { src = Function.prototype.toString.call(window[h]); } catch (e) { return false; }
			src = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');   // an empty hook (nuBuilder Forte's default Setup → Header) does nothing
			return /^\s*function\s*[\w$]*\s*\([^)]*\)\s*\{\s*\}\s*$/.test(src);
		});
	}
	function browseKey(href) {
		try {
			var u = new URL(href, window.location.href), q = u.searchParams;
			if (u.origin !== window.location.origin || q.get('p') !== 'form' || q.get('r') || q.get('print') || q.get('export') || q.get('fmt')) return '';
			return u.pathname + '|' + q.get('f') + '|' + (q.get('lookup') || '') + '|' + (q.get('lo') || '') + '|' + (q.get('iframe') || '') + '|' + (q.get('design') || '');
		} catch (e) { return ''; }
	}
	function sameBrowse(href) { var k = browseKey(href); return k !== '' && k === browseKey(window.location.href); }
	function browseNav(url) { if (!browseGo(url, true)) window.location.href = url; }
	function browseGo(url, push) {
		if (!smoothOk() || !sameBrowse(url)) return false;
		var old = document.querySelector('.nuBrowseCard');
		if (!old) return false;
		if (SM.ctrl) SM.ctrl.abort();
		SM.ctrl = window.AbortController ? new AbortController() : null;
		var act = document.activeElement, focusId = act && act.id && old.contains(act) ? act.id : '', caret = null;
		try { if (act && typeof act.selectionStart === 'number') caret = act.selectionStart; } catch (e) { /* not a text field */ }
		var slow = setTimeout(function () { old.classList.add('nuLvLoading'); old.setAttribute('aria-busy', 'true'); }, 150);
		fetch(url, {credentials: 'same-origin', signal: SM.ctrl ? SM.ctrl.signal : undefined}).then(function (r) {
			if (!r.ok || (r.redirected && !sameBrowse(r.url))) throw new Error('fallback');
			return r.text().then(function (html) { return [html, r.url || url]; });
		}).then(function (res) {
			clearTimeout(slow);
			var m = /window\.nuNext = (\{[\s\S]*?\});<\/script>/.exec(res[0]);
			var doc = new DOMParser().parseFromString(res[0], 'text/html');
			var fresh = doc.querySelector('.nuBrowseCard'), cur = document.querySelector('.nuBrowseCard');
			if (!m || !fresh || !cur) throw new Error('fallback');
			var data = JSON.parse(m[1]);
			if (!data.form || data.form.id !== N.form.id || data.mode !== 'browse' || !data.browse) throw new Error('fallback');
			// the toolbar (export / print only with records …): swapped as well, a different set of buttons = a normal load
			var ids = ['nuExportButton', 'nuPrintButton'];
			if (ids.some(function (id) { return !document.getElementById(id) !== !doc.getElementById(id); })) throw new Error('fallback');
			ids.forEach(function (id) { var a = document.getElementById(id), b = doc.getElementById(id); if (a && b) a.replaceWith(document.importNode(b, true)); });
			document.querySelectorAll('body > .tooltip').forEach(function (t) { t.remove(); });
			var node = document.importNode(fresh, true);
			cur.replaceWith(node);
			N.browse = data.browse;
			N.adv = data.adv;
			if (data.ps) N.ps = data.ps;                           // the new page's scope of hash variables (nuSetProperty …)
			if (data.globals) N.globals = data.globals;
			if (data.props) N.props = data.props;
			if (push !== false) history.pushState({nuBr: 1}, '', res[1]);
			if (doc.title) document.title = doc.title;
			browseAfterSwap(node);
			if (focusId) {
				var f = document.getElementById(focusId);
				if (f) { f.focus({preventScroll: true}); try { if (caret !== null) f.setSelectionRange(caret, caret); } catch (e) { /* select */ } }
			}
			if (node.getBoundingClientRect().top < 0) node.scrollIntoView({block: 'start'});
			document.documentElement.setAttribute('data-nu-lv-swaps', String((+document.documentElement.getAttribute('data-nu-lv-swaps') || 0) + 1));   // for the tests
		}).catch(function (e) {
			clearTimeout(slow);
			if (e && e.name === 'AbortError') return;
			window.location.href = url;
		});
		return true;
	}
	function browseAfterSwap(root) {
		if (window.nuBs && window.nuBs.ready()) root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { window.nuBs.tooltip(el); });
		[['nuOpenMin', root], ['nuBindConfirm', root], ['nuBindPagerJump', root], ['nuAdvSearchInit']].forEach(function (f) {
			if (typeof window[f[0]] === 'function') { try { window[f[0]](f[1]); } catch (e) { /* one helper must not stop the others */ } }
		});
		applyNoSearchMarks(currentNoSearch());
		initSelects(root);
		colFilterSelects(root);
		if (typeof window.nuHighlightSearch === 'function') { try { window.nuHighlightSearch(); } catch (x) { reportError(x.message, 'nuHighlightSearch'); } }
		if (SM.on === true) callHook('nuLoadBrowseGlobal', N.form.id, N.form.code);
		if (N.browse && N.browse.badges) Object.keys(N.browse.badges).forEach(function (i) {
			try { window.nuBrowseStyleBadge(+i, N.browse.badges[i] && N.browse.badges[i].length ? N.browse.badges[i] : undefined); } catch (e) { /* see the load */ }
		});
		if (SM.on === true) callHook('nuOnLoad', N.form.id, N.form.code);
		BR.ctl = null;
		brRestore();
		if (typeof window.nuAttachToolTip === 'function') { try { window.nuAttachToolTip(); } catch (x) { reportError(x.message, 'nuAttachToolTip'); } }
		phoneStack();
		document.dispatchEvent(new CustomEvent('nu:listrefresh', {detail: {card: root}}));
	}
	// long filter lists (review B2): searchable (Tom Select) from 15 values on
	function colFilterSelects(root) {
		if (window.TomSelect) $(root).find('select.nuColumnFilter').each(function () {
			if (this.options.length < 16 || this.tomselect) return;
			try { new window.TomSelect(this, {maxOptions: null, allowEmptyOption: true, controlInput: '<input>', render: {no_results: function () { return '<div class="no-results">' + esc(M.no_results || '–') + '</div>'; }}}); } catch (x) { /* keep the plain select */ }
		});
	}
	if (N.mode === 'browse') {
		window.nuListGo = function (url) { return browseGo(url, true); };   // the page jump of the footer (assets/js/nuapp.js)
		try { history.replaceState(Object.assign({}, history.state || {}, {nuBr: 1}), ''); } catch (e) { /* ignore */ }
		window.addEventListener('popstate', function (e) {
			if (!e.state || !e.state.nuBr) return;
			if (!browseGo(window.location.href, false)) window.location.reload();
		});
		document.addEventListener('click', function (e) {
			if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
			var a = e.target.closest && e.target.closest('.nuBrowseCard a[href]');
			if (!a || a.target || a.hasAttribute('download') || a.getAttribute('href').charAt(0) === '#') return;
			if (browseGo(a.href, true)) e.preventDefault();
		});
	}

	// ---- Run::iFrame objects (same origin: the embedded nuBuilder Next form is reachable directly)
	function frameDoc(id) { var f = g(id); try { return f && f.contentWindow ? f.contentWindow.document : null; } catch (e) { return null; } }
	api('nuGetIframeValue', function (iframeId, elementId, method) {
		var d = frameDoc(iframeId), el = d && d.getElementById(elementId);
		if (!el) return null;
		return method === 'html' ? el.innerHTML : $(el).val();
	});
	api('nuSetIframeValue', function (iframeId, elementId, value, method) {
		var f = g(iframeId), d = frameDoc(iframeId), el = d && d.getElementById(elementId);
		if (!el) return false;
		if (method === 'html') el.innerHTML = value;
		else if (f.contentWindow.nuSetValue) f.contentWindow.nuSetValue(elementId, value);
		else $(el).val(value);
		return true;
	});
	api('nuGetIframeProperty', function (iframeId, property) {
		var f = g(iframeId);
		try { return f && f.contentWindow && f.contentWindow.nuGetProperty ? (f.contentWindow.nuGetProperty(property) ?? null) : null; } catch (e) { return null; }
	});
	api('nuSetIframeProperty', function (iframeId, property, value) {
		var f = g(iframeId);
		try { if (f && f.contentWindow && f.contentWindow.nuSetProperty) f.contentWindow.nuSetProperty(property, value); } catch (e) { /* ignore */ }
	});
	function rerun(id, param, value) {
		var el = g(id);
		if (!el) return;
		if (el.tagName !== 'IFRAME') {   // a run button: the next click uses the new value
			el.setAttribute(param === 'rf' ? 'data-nu-run-filter' : 'data-nu-run-record', value === undefined ? '' : value);
			return;
		}
		var u = new URL(el.getAttribute('src'), window.location.href);
		if (value !== undefined && value !== null && (u.searchParams.get(param) || '') === String(value)) return;
		if (value !== undefined && value !== null) { if (value === '') u.searchParams.delete(param); else u.searchParams.set(param, value); }
		if (param === 'r' && value === '') u.searchParams.delete('r');
		withScope(function (key) {
			u.searchParams.set('ps', key);
			el.setAttribute('src', u.pathname + u.search);
		});
	}
	api('nuFilterRun', function (id, filter) { rerun(id, 'rf', filter); });
	api('nuRecordRun', function (id, recordId) { rerun(id, 'r', recordId === undefined ? undefined : String(recordId)); });

	// ---- subform filters: nuSubformAddFilter({sfName: ['col', …] | {col: {type: 'select' | 'search', all, blank, placeholder, width, float}}})
	function cellText(el) {
		if (!el) return '';
		if (el.type === 'checkbox') return el.checked ? '1' : '0';
		if (el.tagName === 'SELECT') return $(el).find('option:selected').map(function () { return this.text; }).get().join(', ');
		var c = g(el.id + 'code');                   // lookups: the code, as nuBuilder Forte filters (nuSubformFilterCellValue)
		if (c) return String(c.value || '');
		return 'value' in el ? String(el.value) : $(el).text();
	}
	function sfRows(sf) { return $('#' + sf + ' .nuSubformRow').filter(function () { return !this.closest('template'); }); }
	function sfFilterRows(sf, cols) {
		sfRows(sf).each(function () {
			var row = this, prefix = row.id;
			if (row.classList.contains('nuNewRow')) { row.classList.remove('nuFilteredOut'); return; }
			var rowNo = parseInt(String(prefix).slice(sf.length), 10), all = sfRows(sf).length, hookHide = null;
			var show = Object.keys(cols).every(function (col) {
				var f = g(sf + col + '_filter');
				if (!f) return true;
				var txt = cellText(g(prefix + col)), match, isAll = false, blank = false, fv;
				if (f.tagName === 'SELECT') {
					var opt = f.options[f.selectedIndex];
					isAll = !opt || opt.getAttribute('data-nu-all') === 'true';
					fv = opt ? opt.text : '';
					match = isAll || txt.toLowerCase() === fv.toLowerCase();
				} else {
					fv = f.value; blank = fv === '';
					match = blank || txt.toLowerCase().indexOf(fv.toLowerCase()) >= 0;
				}
				// nuBuilder's hook decides per column (the last column's answer counts): true = hide the row
				if (typeof window.nuSubformOnFilterRows === 'function') {
					try { hookHide = !!window.nuSubformOnFilterRows(sf, {val: txt, filter: fv, type: f.tagName === 'SELECT' ? 'select' : 'search', optionAll: isAll, optionBlank: blank, isMatch: match}, rowNo, all); }
					catch (x) { reportError(x.message, 'nuSubformOnFilterRows'); }
					return true;
				}
				return match;
			});
			if (hookHide !== null) show = !hookHide;
			row.classList.toggle('nuFilteredOut', !show);
		});
	}
	function sfDistinct(sf, col) {
		var seen = {}, out = [];
		sfRows(sf).each(function () {
			var prefix = this.id;
			if (this.classList.contains('nuNewRow')) return;
			var t = cellText(g(prefix + col));
			if (t !== '' && !seen[t]) { seen[t] = 1; out.push(t); }
		});
		return out.sort(function (a, b) { return a.localeCompare(b); });
	}
	// nuBuilder's helpers inside nuSubformAddFilter, reachable from form code (0.8.383)
	api('nuSubformFilterArray', function (sf, columns) {
		var out = {}, list = Array.isArray(columns) ? columns : Object.keys(columns || {});
		list.forEach(function (col) {
			var f = g(sf + col + '_filter'), type = Array.isArray(columns) ? 'select' : ((columns[col] || {}).type || 'select');
			var opt = f && f.tagName === 'SELECT' ? f.options[f.selectedIndex] : null;
			out[col] = {type: type, value: f ? (opt ? opt.text : f.value) : '', all: !!(opt && opt.getAttribute('data-nu-all') === 'true')};
		});
		return out;
	});
	api('nuSubformFilterAddObject', function (type, sf, col, filterId, prop) {
		var def = {}; def[sf] = {}; def[sf][col] = $.extend({}, prop || {}, {type: type || 'select'});
		nuSubformAddFilter(def);
		return $('#' + (filterId || sf + col + '_filter'));
	});
	api('nuSubformAddFilter', function (filter) {
		Object.keys(filter || {}).forEach(function (sf) {
			var def = filter[sf], cols = {};
			if (Array.isArray(def)) def.forEach(function (c) { cols[c] = {type: 'select'}; }); else cols = def || {};
			Object.keys(cols).forEach(function (col) {
				var prop = cols[col] || {}, type = prop.type || 'select', id = sf + col + '_filter';
				if (g(id)) return;
				var el;
				if (type === 'search') {
					el = $('<input type="search" class="form-control form-control-sm nuSubformFilter">').attr('id', id);
					if (prop.datalist !== false) {
						var dl = $('<datalist>').attr('id', id + '_list');
						el.attr('list', id + '_list').on('focus', function () {
							dl.empty(); sfDistinct(sf, col).forEach(function (t) { dl.append($('<option>').attr('value', t)); });
							if (typeof window.nuSubformFilterOnAddValues === 'function') { try { window.nuSubformFilterOnAddValues(sf, id); } catch (x) { reportError(x.message, 'nuSubformFilterOnAddValues'); } }
						});
						el = el.add(dl);
					}
					el.filter('input').on('input', function () { sfFilterRows(sf, cols); });
				} else {
					el = $('<select class="form-select form-select-sm nuSubformFilter"></select>').attr('id', id);
					var all = prop.all === undefined ? '(' + nuTranslate('All') + ')' : prop.all;
					var fill = function () {
						var cur = el.val();
						el.find('option:not([data-nu-persistent])').remove();
						sfDistinct(sf, col).forEach(function (t) {
							var r = {add: true, text: t, value: t};
							if (typeof window.nuSubformFilterOnAddValue === 'function') {   // nuBuilder: may change or skip an option
								try { r = window.nuSubformFilterOnAddValue(sf, true, t, t) || r; } catch (x) { reportError(x.message, 'nuSubformFilterOnAddValue'); }
							}
							if (r.add !== false) el.append($('<option>').text(r.text).attr('value', r.value));
						});
						if (typeof window.nuSubformFilterOnAddValues === 'function') { try { window.nuSubformFilterOnAddValues(sf, id); } catch (x) { reportError(x.message, 'nuSubformFilterOnAddValues'); } }
						if (cur !== null) el.val(cur);
					};
					if (all !== false) el.append($('<option value="" data-nu-persistent="true" data-nu-all="true" selected>').text(all));
					if (prop.blank !== false) el.append($('<option value="" data-nu-persistent="true">'));
					el.on('focus mousedown', fill).on('change', function () { sfFilterRows(sf, cols); });
				}
				if (prop.placeholder) el.filter('input').attr('placeholder', nuTranslate(prop.placeholder));
				if (prop.width) el.css('width', prop.width);
				var title = g('title_' + sf + col);
				if (title) $(title).append(el);
				else {                                       // form-layout subform: a filter bar above the rows
					var bar = $('#' + sf + ' > .nuSubformFilterBar');
					if (!bar.length) bar = $('<div class="nuSubformFilterBar d-flex flex-wrap gap-2 mb-2"></div>').prependTo('#' + sf);
					var lbl = $('label[for$="' + col + '"]').first().clone().find('.nuMarks').remove().end().text().trim() || col;
					bar.append($('<div class="nuSubformFilterItem"></div>').append($('<div class="small text-body-secondary"></div>').text(lbl)).append(el));
				}
			});
		});
	});

	// ---- labels above their objects (absolute layout; auto and grid layouts have them there already)
	api('nuLabelOnTop', function (include, exclude, offsetTop, offsetLeft) {
		offsetTop = offsetTop === undefined ? -18 : offsetTop;
		offsetLeft = offsetLeft || 0;
		include = include || Object.keys(N.objects || {});
		exclude = exclude || [];
		arr(include).forEach(function (id) {
			if (exclude.indexOf(id) >= 0) return;
			var el = g(id), lab = g('label_' + id);
			if (!el || !lab) return;
			var box = el.closest('.nuAbs') || el, lbox = lab.closest('.nuAbs') || lab;
			if (box === lbox) {                          // label and object in one positioned box: move the label above the object
				lab.classList.remove('nuLabelLeft');
				lab.classList.add('nuLabelTop');
				if (offsetTop !== -18) { lab.style.bottom = 'auto'; lab.style.top = offsetTop + 'px'; }
				if (offsetLeft) lab.style.left = offsetLeft + 'px';
				lab.style.textAlign = 'left';
			} else if (box.style.top !== '') {
				lbox.style.top = (parseFloat(box.style.top) + offsetTop) + 'px';
				lbox.style.left = (parseFloat(box.style.left) + offsetLeft) + 'px';
				lab.style.textAlign = 'left';
			}
			el.setAttribute('data-nu-label-position', 'top');
		});
	});
	if (!$.fn.nuLabelOnTop) $.fn.nuLabelOnTop = function (offsetTop, offsetLeft) { nuLabelOnTop(this.map(function () { return this.id; }).get(), [], offsetTop, offsetLeft); return this; };

	// ---- hooks: tabs and breadcrumbs (nuOnSelectTab may cancel; nuOnTabSelected after the switch)
	$(document).on('show.bs.tab', '.nuTab', function (e) {
		if (window.nuOnSelectTab) {
			try { if (window.nuOnSelectTab(e.target) === false) e.preventDefault(); } catch (x) { reportError(x.message, 'nuOnSelectTab'); }
		}
	});
	// nuBuilder Forte marks the selected tab with nuTabSelected – form code reads $('.nuTabSelected')[0].id (vertical tab menus …)
	$(document).on('shown.bs.tab', '.nuTab', function (e) {
		$('.nuTab.nuTabSelected').not(e.target).removeClass('nuTabSelected');
		e.target.classList.add('nuTabSelected');
	});
	$(document).on('shown.bs.tab', '.nuTab', function (e) {
		if (N.autoTab) { N.autoTab = false; return; }   // chosen by the page (Notes, 0.8.508), not by the user: not remembered
		try { sessionStorage.setItem(tabKey(), e.target.getAttribute('data-nu-tab')); } catch (x) { /* ignore */ }
		['nuOnTabSelectedGlobal', 'nuOnTabSelected'].forEach(function (fn) {
			if (typeof window[fn] === 'function') { try { window[fn](e.target); } catch (x) { reportError(x.message, fn); } }
		});
	});
	// the Back button (#nuBackButton) goes to a breadcrumb too: the same hook may stop it
	$(document).on('click', 'a.nuBreadcrumb, a#nuBackButton', function (e) {
		if (typeof window.nuOnBeforeGetBreadcrumb !== 'function') return;
		var n = this.id === 'nuBackButton' ? parseInt(this.getAttribute('data-nu-crumb'), 10) : parseInt(String(this.id).replace('nuBreadcrumb', ''), 10);
		try { if (window.nuOnBeforeGetBreadcrumb(n) === false) e.preventDefault(); } catch (x) { reportError(x.message, 'nuOnBeforeGetBreadcrumb'); }
	});
	function callHook(name) {
		if (typeof window[name] !== 'function') return undefined;
		try { return window[name].apply(window, Array.prototype.slice.call(arguments, 1)); }
		catch (x) { reportError(x.message, name); console.error(x); }
		return undefined;
	}
	N.callHook = callHook;

	// =================================================================== keyboard shortcuts + options menu (nuBuilder: Ctrl+Shift+key)
	// Only what exists in nuBuilder Next and the user may use (N.tools from the server, buttons that are on the page).
	function openTab(url) { window.open(url, '_blank', 'noopener'); }
	function builderUrl(open) { return N.base + '?p=builder&f=' + encodeURIComponent(N.form.id) + (open ? '&open=' + open : ''); }
	function goPage(p) {
		var b = N.browse || {}, u = new URL(window.location.href);
		p = Math.max(1, Math.min(b.pages || 1, p));
		if (p === (b.page || 1)) return;
		u.searchParams.set('pg', p);
		browseNav(u.toString());
	}
	function nextTab(step) {
		var tabs = $('.nuTab:visible').get(), cur = tabs.indexOf(selectedTab());
		if (!tabs.length) return;
		var t = tabs[(cur + step + tabs.length) % tabs.length];
		nuSelectTab(+t.getAttribute('data-nu-tab'));
	}
	function showFormInfo() {
		var panel = g('nuFormInfoBtn');                       // the (i) panel next to Delete (0.8.435) – Ctrl+Shift+M opens it (0.8.446)
		if (panel && window.nuBs) { nuBs.dropdown(panel).show(); return; }
		var S = N.shortcuts || {}, rows = [[S.info_form, N.form.description], [S.info_code, N.form.code], ['ID', N.form.id], [S.info_type, N.form.type],
			[S.info_table, N.form.table], [S.info_pk, N.form.primary_key], [S.info_layout, N.form.layout]];
		if (N.mode === 'edit') rows.push([S.info_record, N.record_id]);
		nuMessage(S.form_info || 'Form info', rows.filter(function (r) { return r[1]; }).map(function (r) { return '<b>' + esc(r[0]) + ':</b> ' + esc(r[1]); }));
	}
	function logoutAsk() {
		if (!window.confirm((N.shortcuts || {}).logout_confirm || 'Log out?')) return;
		var f = document.createElement('form');
		f.method = 'post'; f.action = N.base + '?p=logout';
		var i = document.createElement('input'); i.type = 'hidden'; i.name = '_csrf'; i.value = N.csrf; f.appendChild(i);
		document.body.appendChild(f); f.submit();
	}
	function shortcutActions() {
		var T = N.tools || {}, ga = !!N.user.globeadmin, edit = N.mode === 'edit', browse = N.mode === 'browse';
		var has = function (id) { return !!g(id); };
		// [key, label key, icon, group, available, action] – group 0: a keyboard shortcut only, not in the options menu (0.8.446: add, print,
		// search, form info, form properties and object list have buttons of their own on the page; 0.8.517: arrange objects = the form
		// builder button; save / delete / clone = the record buttons; log out = the user menu; 0.8.818: refresh, database, logged-in users,
		// File Manager – their keys stay)
		return [
			['a', 'arrange', 'bi-arrows-move', 0, edit && T.design, function () { openTab(builderUrl('arrange')); }],
			['h', 'add_object', 'bi-plus-lg', 1, edit && T.design, function () { openTab(builderUrl('add')); }],
			// 0.8.818: design mode → Code → Browse SQL; 0.8.932: Ctrl+Shift+B, the pane of the list where there is one
			['b', 'browse_sql', 'bi-filetype-sql', 0, browse && (!!T.browsesql || !!N.browseSqlOpen), function () { if (N.browseSqlOpen) N.browseSqlOpen(); else window.location.href = T.browsesql; }],   // 0.8.1111: shortcut only – the entry is in Design ▾
			// the form's table in phpMyAdmin / Adminer (0.8.923; Ctrl+Shift+E stays the same without a menu entry of its own)
			['', 'db_table', 'bi-table', 1, T.pma && !!N.form.table, function () { nuVendorLogin('DB', N.form.table); }],
			['', 'form_access', 'bi-people', 1, !!T.formaccess && typeof window.nuFormAccessOpen === 'function', function () { window.nuFormAccessOpen(N.form.id); }],   // 0.8.683
			['', 'code_search', 'bi-search', 1, !!T.codesearch, function () { openTab(T.codesearch); }],   // 0.8.1172: the code search with this form as filter
			['f', 'form_properties', 'bi-gear', 0, T.design, function () { openTab(builderUrl('form')); }],
			['o', 'object_list', 'bi-list-ul', 0, T.design, function () { openTab(builderUrl('objects')); }],
			['s', 'save', 'bi-floppy', 0, edit && has('nuSaveButton'), function () { if (document.activeElement) $(document.activeElement).trigger('blur'); nuSaveAction(); }],
			['y', 'delete', 'bi-trash', 0, edit && has('nuDeleteButton'), function () { nuDeleteAction(); }],
			['c', 'clone', 'bi-copy', 0, edit && has('nuCloneButton'), function () { nuCloneAction(); }],
			['s', 'search', 'bi-search', 0, browse && has('nuSearchField'), function () { nuSearchAction(); }],
			['a', 'add', 'bi-plus-lg', 0, browse && has('nuAddButton'), function () { $('#nuAddButton').trigger('click'); }],
			['p', 'print', 'bi-printer', 0, browse && has('nuPrintButton'), function () { var h = $('#nuPrintButton').attr('data-print-url') || $('#nuPrintButton').attr('href'); go(function () { return printUrl(h); }, 'blank'); }],
			['?', 'help', 'bi-book', 3, true, function () { openTab(N.base + '?p=help#forms-use'); }],
			['r', 'refresh', 'bi-arrow-repeat', 0, true, function () { window.location.reload(); }],
			['d', 'debug', 'bi-bug', 4, T.debug, function () { openTab(N.base + '?p=debug'); }],
			['e', 'database', 'bi-database', 0, T.pma, function () { nuVendorLogin('DB', N.form.table || ''); }],   // the primary database tool (0.8.282)
			['j', 'sessions', 'bi-key', 0, T.sessions, function () { openTab(N.base + '?p=sessions'); }],
			['', 'file_manager', 'bi-folder2-open', 0, T.tfm, function () { nuVendorLogin('TFM'); }],   // no key: Ctrl+Shift+Q is Change password (nuBuilder Forte; review D8 – it meant both)
			['q', 'password', 'bi-key', 5, edit && T.password, function () { openTab(N.base + '?p=password'); }],
			['m', 'form_info', 'bi-info-circle', 0, ga, showFormInfo],
			['x', 'properties', 'bi-braces', 5, ga, function () { showHash(); }],
			// all columns of the open record with type and stored value (new tab); no shortcut – Ctrl+Shift+I opens the browser's tools
			['', 'inspect', 'bi-table', 5, ga && edit && N.record_id && N.record_id !== '-1' && !!N.form.table, function () {
				openTab(N.base + '?p=inspect&f=' + encodeURIComponent(N.form.id) + '&r=' + encodeURIComponent(N.record_id));
			}],
			['l', 'logout', 'bi-box-arrow-right', 0, !N.iframe, logoutAsk],   // 0.8.517: Log out is in the user menu of the top bar
			// not in the menu (navigation keys, like nuBuilder)
			['arrowright', 'tab_next', '', 0, edit && $('.nuTab').length > 1, function () { nextTab(1); }],
			['arrowleft', 'tab_prev', '', 0, edit && $('.nuTab').length > 1, function () { nextTab(-1); }],
			['arrowright', 'page_next', '', 0, browse, function () { goPage((N.browse.page || 1) + 1); }],
			['arrowleft', 'page_prev', '', 0, browse, function () { goPage((N.browse.page || 1) - 1); }],
			['home', 'page_first', '', 0, browse, function () { goPage(1); }],
			['end', 'page_last', '', 0, browse, function () { goPage(N.browse.pages || 1); }]
		].filter(function (a) { return a[4]; });
	}
	// Mac: Control+Shift (⌃⇧) is shown – it never collides with macOS or the browser; Cmd+Shift works too where the browser lets it through
	var IS_MAC = /Mac|iPhone|iPad/i.test([navigator.userAgentData && navigator.userAgentData.platform, navigator.platform, navigator.userAgent].join(' '));
	// touch device without mouse / keyboard (phone, tablet): no Ctrl key – the options menu shows no shortcuts
	var TOUCH_ONLY = !!(window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches);
	function keyLabel(k) { var n = k.length === 1 ? k.toUpperCase() : k.charAt(0).toUpperCase() + k.slice(1); return IS_MAC ? '⌃⇧' + n : 'Ctrl+Shift+' + n; }
	document.addEventListener('keydown', function (e) {
		if (!(e.ctrlKey || e.metaKey) || !e.shiftKey || e.altKey) return;
		if ($('.modal.show').length) return;                 // code editor, messages: their own keys
		var key = (e.key || '').toLowerCase();
		if (e.key === '?' || (e.code === 'Slash' && !/^[a-z0-9]$/.test(key))) key = '?';   // ? on every keyboard layout
		if (N.mode === 'browse' && /^Digit[1-9]$/.test(e.code || '') && g('nuSearchField')) {   // Ctrl+Shift+1…9: column in / out of the search
			e.preventDefault();
			var col = Number(e.code.slice(5)) - 1, list = currentNoSearch(), at = list.indexOf(col);
			if (at >= 0) list.splice(at, 1); else list.push(col);
			applyNoSearch(list);
			return;
		}
		var act = shortcutActions().filter(function (a) { return a[0] === key; })[0];
		if (!act) return;
		e.preventDefault();
		act[5]();
	}, true);
	/** Hash viewer (globeadmin): every #variable# of this form with its value and where it comes from. */
	function showHash() {
		withScope(function () {
			call('hash', {values: N.mode === 'edit' ? mainValues() : {}}).then(function (res) {
				if (!res || !res.ok) { nuDisplayError(res); return; }
				var src = M.hash_src || {}, locked = res.locked || [];
				var rows = res.hash.map(function (h) {
					return '<tr data-hash-row="' + esc(h.name) + '"><td><code>#' + esc(h.name) + '#</code></td><td class="text-break">' + esc(h.value) + '</td><td><span class="badge nuHashSrc nuHashSrc-' + esc(h.source) + '">' + esc(src[h.source] || h.source) + '</span>'
						+ (locked.indexOf(h.name) >= 0 ? ' <i class="bi bi-lock" title="' + esc(M.hash_locked || 'set in PHP') + '"></i>' : '') + '</td></tr>';
				}).join('');
				nuMessage(N.shortcuts.properties || 'Hash', ['<input type="search" class="form-control form-control-sm mb-2" id="nuHashFilter" placeholder="' + esc(M.hash_filter || 'Filter') + '">'
					+ '<div class="nuHashTable"><table class="table table-sm small mb-0" id="nuHashTable"><thead><tr><th>' + esc(M.hash_name || 'Name') + '</th><th>' + esc(M.hash_value || 'Value') + '</th><th>' + esc(M.hash_source || 'Source') + '</th></tr></thead><tbody>' + rows + '</tbody></table></div>']);
				$('#nuHashFilter').on('input', function () {
					var q = this.value.toLowerCase();
					$('#nuHashTable tbody tr').each(function () { this.hidden = q !== '' && this.textContent.toLowerCase().indexOf(q) < 0; });
				}).trigger('focus');
			});
		});
	}
	N.showHash = showHash;

	function buildOptionsMenu() {
		var holder = g('nuActionHolder');
		if (!holder || g('nuOptions')) return;
		var S = N.shortcuts || {}, acts = shortcutActions().filter(function (a) { return a[3] > 0; });
		var wrap = $('<div class="dropdown nuOptionsMenu"></div>');
		var btn = $('<button type="button" class="btn btn-outline-secondary" id="nuOptions" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>')
			.attr({title: S.options || 'Options', 'aria-label': S.options || 'Options'});
		var ul = $('<ul class="dropdown-menu nuOptionsList"></ul>');
		phoneDesignItems(ul);
		ul.append($('<li><h6 class="dropdown-header"></h6></li>').find('h6').text(S.options || 'Options').end());
		var last = null;
		acts.forEach(function (a) {
			if (last !== null && a[3] !== last) ul.append('<li><hr class="dropdown-divider"></li>');
			last = a[3];
			var b = $('<button type="button" class="dropdown-item d-flex align-items-center gap-2"></button>').attr({'data-nu-shortcut': a[0], 'data-nu-act': a[1]})
				.append($('<i class="bi"></i>').addClass(a[2]))
				.append($('<span class="me-auto"></span>').text(S[a[1]] || a[1]))
				.append(TOUCH_ONLY || !a[0] ? null : $('<kbd class="nuKbd"></kbd>').text(keyLabel(a[0])));
			b.on('click', a[5]);
			ul.append($('<li></li>').append(b));
		});
		// audit trail (0.8.350): all changes of this record
		if (N.audit) {                                                   // with the record tools, before the last group (log out)
			var hist = $('<li></li>').append($('<a class="dropdown-item d-flex align-items-center gap-2" id="nuAuditHistory"></a>').attr('href', N.audit.url)
				.append('<i class="bi bi-clock-history"></i>').append($('<span class="me-auto"></span>').text(N.audit.label)));
			var lastHr = ul.children('li').filter(function () { return $(this).find('hr').length > 0; }).last();
			if (lastHr.length) hist.insertBefore(lastHr); else ul.append(hist);
		}
		// nuBuilder's <table>_nulog of this record (0.8.349): who added / last edited it
		if (N.nulog) {
			ul.append('<li><hr class="dropdown-divider"></li>');
			var info = $('<li class="px-3 py-1 small text-body-secondary nuNulogInfo"></li>').attr('title', N.nulog.column);
			if (!N.nulog.added && !N.nulog.edited) info.text(N.nulog.none);
			[N.nulog.added, N.nulog.edited].forEach(function (t) { if (t) info.append($('<div></div>').text(t)); });
			ul.append(info);
		}
		wrap.append(btn).append(ul).appendTo(holder);
		btn.on('shown.bs.dropdown', function () { callHook('nuOnOptionsListLoadedGlobal'); });   // nuBuilder: each time the list opens
	}
	N.shortcutActions = shortcutActions;

	// phones (0.8.1001, the owner picked mockup C): Design, its ▾ entries, the (i) form info and the layout switch are hidden in the
	// toolbar below 576 px (nuapp.css) and offered at the top of the ⋮ menu instead – items li.nuOptPhone, shown only there
	function phoneDesignItems(ul) {
		var ds = g('nuDesignButton'), sql = g('nuBrowseSqlEdit'), cols = g('nuBrowseColsEdit'), info = g('nuFormInfoBtn'), seg = g('nuLayoutSeg');
		var items = [];
		var item = function (icon, text, act) {
			var el = typeof act === 'string' ? $('<a class="dropdown-item d-flex align-items-center gap-2"></a>').attr('href', act) : $('<button type="button" class="dropdown-item d-flex align-items-center gap-2"></button>').on('click', act);
			return el.append($('<i class="bi" aria-hidden="true"></i>').addClass(icon)).append($('<span class="me-auto"></span>').text(text));
		};
		if (ds) items.push(item('bi-pencil-square', (ds.textContent || '').trim(), ds.getAttribute('href')).attr('data-nu-opt', 'design'));
		[[sql, 'bi-filetype-sql', 'browse_sql'], [g('nuBrowsePhpEdit'), 'bi-filetype-php', 'browse_php'], [g('nuBrowseJsEdit'), 'bi-filetype-js', 'browse_js'], [cols, 'bi-layout-three-columns', 'columns']].forEach(function (x) {
			if (x[0]) items.push(item(x[1], x[0].getAttribute('data-nu-full') || (x[0].textContent || '').trim(), function () { x[0].click(); }).attr('data-nu-opt', x[2]));
		});
		if (info) items.push(item('bi-info-circle', info.getAttribute('title') || 'Info', function () {
			var src = g('nuFormInfo'), box = $('<div class="nuFormInfo nuFormInfoPhone"></div>');
			if (src) box.append($(src).children().not(':first').clone());
			box.find('[id]').removeAttr('id');
			nuMessage(info.getAttribute('title') || 'Info', [box.prop('outerHTML')]);
		}).attr('data-nu-opt', 'info'));
		var links = seg ? $(seg).children('a') : $();
		if (!items.length && !links.length) return;
		items.forEach(function (el) { ul.append($('<li class="nuOptPhone"></li>').append(el)); });
		if (links.length) {
			var grp = $('<div class="btn-group btn-group-sm nuSeg nuOptLayout" role="group"></div>').attr('aria-label', seg.getAttribute('aria-label') || '');
			links.each(function () { grp.append($(this).clone().removeAttr('id')); });
			ul.append($('<li class="nuOptPhone" data-nu-opt="layout"></li>').append(grp));
		}
		ul.append('<li class="nuOptPhone"><hr class="dropdown-divider"></li>');
	}

	// =================================================================== start (after the form's own scripts)
	window.nuNextStart = function () {
		callHook('nuBeforeAddActionButtons');
		$('#nuSaveButton').on('click', function () { window.nuSaveAction(); });
		$('#nuDeleteButton').on('click', function () { window.nuDeleteAction(); });
		$('#nuCloneButton').on('click', function () { window.nuCloneAction(); });
		$('#nuNewButton, #nuAddButton').on('click', function () {
			// in a lookup window the new record stays in the lookup (and is chosen after saving)
			var lo = new URL(window.location.href).searchParams.get('lo');
			var extra = N.lookup ? $.extend({lookup: N.lookup}, lo ? {lo: lo} : {}) : (N.iframe ? {iframe: '1'} : {});
			go(function () { return formUrl(N.form.id, '-1', extra); });
		});
		$('#nuJsErrors').on('click', function () { nuMessage(M.js_errors || 'JS errors', N.jsErrors.map(esc)); });
		$(document).on('click', '.nuPhAdd', function () { $('#nuAddButton').trigger('click'); });   // phones: Add next to the search (0.8.1003)

		if (N.mode === 'edit') {
			if (window.nuOnEditorLoad) { try { window.nuOnEditorLoad(); } catch (x) { reportError(x.message, 'nuOnEditorLoad'); } }
			else $('#nuEditForm textarea.nuEditor').each(function () { nuInitTinyMCE(this.id); });
			// the last tab only for the same record shown again (0.8.520): after Save, a reload, back / forward or another layout of it –
			// a record opened anew (from the list, a link, a lookup …) starts on the first tab
			var sameRecord = function () {
				if (N.saved) return true;
				var nav = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
				if (nav && (nav.type === 'reload' || nav.type === 'back_forward')) return true;
				try {
					var ref = new URL(document.referrer), here = new URL(window.location.href);
					return ref.origin === here.origin && ref.pathname === here.pathname && ref.searchParams.get('p') === 'form'
						&& ref.searchParams.get('f') === N.form.id && ref.searchParams.get('r') === String(N.record_id);
				} catch (x) { return false; }
			};
			try {
				var t = sessionStorage.getItem(tabKey());
				if (sameRecord() && t !== null && g('nuTab' + t) && $(g('nuTab' + t)).parent().is(':visible')) nuSelectTab(+t);
				else sessionStorage.removeItem(tabKey());
			} catch (x) { /* ignore */ }
			// nuBuilder's Notes form (0.8.508): an existing note with an empty editor but text on the tab Additional opens that tab
			var only = {nunotes: ['not_content', 'not_text']}[N.form.id];
			if (only && !N.is_new) {
				var empty = function (v) { return String(v || '').replace(/<[^>]*>/g, '').replace(/&nbsp;|\u00a0/g, ' ').trim() === ''; };
				var other = g(only[1]), pane = other && other.closest('.nuTabPane');
				var tb = pane && g(pane.getAttribute('aria-labelledby'));
				if (tb && !tb.classList.contains('active') && empty(nuGetValue(only[0])) && !empty(nuGetValue(only[1]))) { N.autoTab = true; nuSelectTab(tb); }
			}
			try {
				var pending = JSON.parse(sessionStorage.getItem('nuNextPendingJs') || '[]');
				sessionStorage.removeItem('nuNextPendingJs');
				pending.forEach(function (js) { try { globalEval(js); } catch (e) { reportError(e.message, 'After Save'); } });
			} catch (x) { /* ignore */ }
			if (N.saved) {
				nuToast(M.saved || 'Saved.', 'success');
				var u = new URL(window.location.href);
				u.searchParams.delete('saved');
				history.replaceState(null, '', u.toString());
			}
		}
		try {   // notice handed over from the previous page (e.g. "deleted" before going back to the browse)
			var nt = JSON.parse(sessionStorage.getItem('nuNextToast') || 'null');
			sessionStorage.removeItem('nuNextToast');
			if (nt) nuToast(nt[0], nt[1]);
		} catch (x) { /* ignore */ }
		if (N.mode === 'browse' && N.lookup && (!window.nuHasKeyboard || window.nuHasKeyboard())) $('#nuSearchField').trigger('focus');   // no on-screen keyboard on touch screens
		if (N.mode === 'browse') applyNoSearchMarks(currentNoSearch());
		if (N.mode === 'edit') markCodeFields(document);
		buildOptionsMenu();
		initSelects(document);
		// nuBuilder calculates calc objects once after building the edit form, without marking it as edited
		if (N.mode === 'edit' && typeof window.nuCalculateForm === 'function') {
			try { window.nuCalculateForm(false); } catch (x) { reportError(x.message, 'nuCalculateForm'); }
		}
		// object event onnuload (nuBuilder nuEvaluateOnLoadEvents): after the calc, before the load hooks; "(this)" / "this." name the
		// object by its id, as nuBuilder rewrites them – B7 of the gap analysis, 0.8.462
		if (N.mode === 'edit') {
			$('#nuEditForm [onnuload]').each(function () {
				if (this.closest('template') || this.closest('.nuSubform')) return;
				var id = this.id, js = String(this.getAttribute('onnuload') || '');
				if (!id || !js.trim()) return;
				try { (0, eval)(js.split('(this)').join('("#' + id + '")').split('this.').join(id + '.')); }   // eslint-disable-line no-eval
				catch (x) { reportError(x.message, 'onnuload ' + id); }
			});
		}
		// nuBuilder marks the search words in the browse list before the load hooks (nuBrowseTable → nuHighlightSearch, 0.8.427)
		if (N.mode === 'browse' && typeof window.nuHighlightSearch === 'function') {
			try { window.nuHighlightSearch(); } catch (x) { reportError(x.message, 'nuHighlightSearch'); }
		}
		// nuBuilder: global load hooks (setup header), then the form's nuOnLoad(formId, formCode)
		callHook(N.mode === 'browse' ? 'nuLoadBrowseGlobal' : 'nuLoadEditGlobal', N.form.id, N.form.code);
		// list columns shown as badges in design mode (0.8.688) – before nuOnLoad, so the form's own nuBrowseStyleBadge call still wins
		if (N.browse && N.browse.badges) Object.keys(N.browse.badges).forEach(function (i) {
			try { window.nuBrowseStyleBadge(+i, N.browse.badges[i] && N.browse.badges[i].length ? N.browse.badges[i] : undefined); }
			catch (e) { if (N.dev_mode) console.warn('badge', i, e); }
		});
		winCarryRestore();
		try { callHook('nuOnLoad', N.form.id, N.form.code); } finally { $('#nuBrowseTable').removeClass('nuBrowseJsWait'); }   // rows shown after the form's code (0.8.1150)
		forteHidden();
		brRestore();                                                       // column widths dragged in this tab (0.8.650)
		if (typeof window.nuNextPageHook === 'function') window.nuNextPageHook();   // Global JS: nuOnPageLoad / nu:pageload after nuOnLoad (0.8.523)
		if (N.mode === 'edit') {                                            // nuAfterSave of the save that reloaded this page (0.8.383)
			try {
				var as = sessionStorage.getItem('nuNextAfterSave');
				if (as !== null) {
					sessionStorage.removeItem('nuNextAfterSave');
					if (as === N.form.id + '|' + N.record_id) {
						window.nuTimesSaved = (window.nuTimesSaved || 0) + 1;
						callHook('nuAfterSaveGlobal');
						if (window.nuAfterSave) { try { window.nuAfterSave(); } catch (x) { reportError(x.message, 'nuAfterSave'); } }
					}
				}
			} catch (x) { /* ignore */ }
		}
		// nuBuilder ends nuBuildForm with nuSetSaved(true): values set by the form's own code while loading (nuSetValue …) do not
		// count as an edit – Save stays normal and leaving does not ask. The fields keep their nuEdited mark, so they are still saved (0.8.383).
		if (N.mode === 'edit' && N.edited) {
			N.edited = false;
			$('#nuSaveButton').removeClass('nuSaveEdited');
			callHook('nuOnSetSaved', true);
		}
		// tooltip icons (nu-tooltip-icon attribute, assets/js/nu/nutooltip.js) – after nuOnLoad, which may set nuOnToolTipIcon or attributes
		if (typeof window.nuAttachToolTip === 'function') { try { window.nuAttachToolTip(); } catch (x) { reportError(x.message, 'nuAttachToolTip'); } }
		// chart objects – after nuOnLoad, so the form's code can still set the data arrays
		if (N.mode === 'edit') initCharts(document);
		// form buttons: text colour on backgrounds of the form's CSS, tooltip for cut-off labels – after nuOnLoad, which may restyle them
		if (N.mode === 'edit') fitButtons(document);
		// read-only text fields cut their value: wider where the layout has room, else the value as tooltip – after nuOnLoad
		if (N.mode === 'edit') fitReadonly(document);
		if (N.mode === 'edit') { fitCanvases(); watchCanvases(); }   // canvas as tall as its objects show (0.8.965)
		// code snippets: Source code as an inline code editor – after nuOnLoad, which may disable the fields
		if (N.mode === 'edit') inlineCodeFields();
		// phones (review F1, 0.8.764): fixed-position forms stack their objects – after nuOnLoad, which may switch it off
		phoneStack();
		if (N.mode === 'edit') fitWide();   // wide fixed-layout forms: page margins away while the form would scroll sideways (0.8.988)
		if (N.mode === 'edit') growFrames();
		// first field gets the focus (mouse / trackpad only, see nuFocusFirst in assets/js/nuapp.js) – after nuOnLoad, which may set its own
		// (in a popup yes, in an iframe object embedded in another form no – it would take the focus away from that form)
		var embedded = false;
		if (N.iframe) { try { embedded = !window.frameElement || !window.frameElement.closest('.modal'); } catch (x) { embedded = true; } }
		if (N.mode === 'edit' && !embedded && window.nuFocusFirst) setTimeout(function () { window.nuFocusFirst(g('nuEditForm')); }, 0);
		$(document).on('change', '.nuColumnFilter', function () { if (this.form.requestSubmit) this.form.requestSubmit(); else this.form.submit(); });
		colFilterSelects(document);

		// unsaved changes: links, Back, breadcrumbs and run buttons ask Save / Discard / Keep editing; the browser's back button,
		// reload and closing the tab get the browser's own question (nuLeaveGuard, assets/js/nuapp.js)
		if (window.nuLeaveGuard) {
			// a launch form is never saved: leaving it does not ask, as nuBuilder Forte's nuGetBreadcrumb (0.8.975)
			if (N.mode === 'edit' && !N.iframe && (N.form || {}).type !== 'launch') window.nuLeaveGuard.add({
				dirty: function () { return !!N.edited; },
				discard: function () { N.edited = false; },
				save: function (go) { N.afterSave = go; nuSaveAction(); }
			});
		} else window.addEventListener('beforeunload', function (e) {
			if (N.edited && N.mode === 'edit' && !N.iframe && (N.form || {}).type !== 'launch') { e.preventDefault(); e.returnValue = M.unsaved || ''; }
		});
	};
})();
