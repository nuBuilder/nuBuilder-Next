/* nuBuilder Next – procedure editor (pages/nuprocedures.php): code editors, snippets, test run with nuOnProcedureRun(), tabs */
(function () {
	'use strict';
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
	var D = {};
	try { D = JSON.parse($('#procData').textContent); } catch (e) { /* ignore */ }
	var M = D.msg || {};
	var form = $('#nuRecordForm');
	if (!form) return;
	var ro = !!form.querySelector('fieldset[disabled]');
	var editors = {};

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') e.textContent = attrs[k]; else if (k === 'class') e.className = attrs[k]; else e.setAttribute(k, attrs[k]); });
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}

	// ------------------------------------------------------------------ code editors (CodeMirror 5)
	$$('textarea.procCode').forEach(function (ta) {
		if (!window.CodeMirror) return;
		var mode = ta.getAttribute('data-mode') === 'php' ? {name: 'php', startOpen: true} : 'javascript';
		var cm = CodeMirror.fromTextArea(ta, {mode: mode, lineNumbers: true, lineWrapping: true, matchBrackets: true, autoCloseBrackets: true,
			indentUnit: 4, tabSize: 4, indentWithTabs: true, readOnly: ro ? 'nocursor' : false});
		cm.getWrapperElement().classList.add('procCM');
		if (ta.classList.contains('is-invalid')) cm.getWrapperElement().classList.add('is-invalid');
		cm.on('change', function () { cm.save(); ta.dispatchEvent(new Event('input', {bubbles: true})); });   // marks the form as changed (red Save)
		editors[ta.id] = cm;
		var tools = $('.procCodeTools[data-for="' + ta.id + '"]');
		if (tools && window.NuCodeTools) NuCodeTools.attach(cm, tools, {lang: ta.getAttribute('data-mode') === 'php' ? 'PHP' : 'JS', db: D.db, readOnly: ro});
		if (tools && !ro) {
			if (ta.id === 'sph_php' && window.NuHashVars && D.catalog) NuHashVars.attach(cm, tools, {catalog: D.catalog});
			snippetMenu(cm, tools, ta.id === 'sph_php' ? 'php' : 'javascript');
		}
	});
	// editors in hidden tabs need a refresh when shown
	$$('#procTabs [data-bs-toggle="tab"]').forEach(function (b) {
		b.addEventListener('shown.bs.tab', function () { Object.keys(editors).forEach(function (k) { editors[k].refresh(); }); });
	});

	/** "Insert snippet": PHP or JavaScript code snippets (zzzzsys_code_snippet), inserted at the cursor. */
	function snippetMenu(cm, host, lang) {
		var list = (D.snippets || []).filter(function (s) { return String(s[3] || '').toLowerCase() === lang; });
		if (!list.length || !nuBs.ready()) return;
		var btn = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-bs-toggle': 'dropdown', 'data-bs-auto-close': 'outside', 'aria-expanded': 'false', title: M.snippet},
			[el('i', {'class': 'bi bi-file-earmark-code me-1'}), M.snippet || 'Snippet']);
		var search = el('input', {type: 'search', 'class': 'form-control form-control-sm mb-1', placeholder: M.search || '', 'aria-label': M.search || ''});
		var box = el('div', {'class': 'nuHashVarsList'});
		var menu = el('div', {'class': 'dropdown-menu dropdown-menu-end p-2 nuHashVarsMenu'}, [search, box]);
		var wrap = el('div', {'class': 'dropdown procSnippets'}, [btn, menu]);
		host.appendChild(wrap);
		var dd = nuBs.dropdown(btn);
		function render() {
			var q = search.value.trim().toLowerCase();
			box.innerHTML = '';
			list.filter(function (s) { return !q || (s[0] + ' ' + s[1]).toLowerCase().indexOf(q) >= 0; }).forEach(function (s) {
				var b = el('button', {type: 'button', 'class': 'dropdown-item px-1', 'data-code': s[0]}, [el('div', {'class': 'fw-semibold small', text: s[0]}), el('div', {'class': 'small text-body-secondary text-wrap', text: s[1] || ''})]);
				b.addEventListener('click', function () { dd.hide(); cm.replaceSelection(s[2] || ''); cm.focus(); });
				box.appendChild(b);
			});
			if (!box.children.length) box.appendChild(el('div', {'class': 'small text-body-secondary p-1', text: M.none || ''}));
		}
		btn.addEventListener('show.bs.dropdown', function () { search.value = ''; render(); });
		btn.addEventListener('shown.bs.dropdown', function () { search.focus(); });
		search.addEventListener('input', render);
	}

	// ------------------------------------------------------------------ JS tab: example
	var ex = $('#procJsExample');
	if (ex) ex.addEventListener('click', function () {
		var cm = editors.sph_javascript;
		var code = "function nuOnProcedureRun() {\n\t// runs before the procedure when it is started from here\n\t// nuSetProperty('myproperty', 'myvalue');   // → #myproperty# in the PHP code\n\t// return false;                             // cancels the run\n}\n";
		if (cm) { if (cm.getValue().trim() === '' || window.confirm(ex.textContent.trim() + '?')) cm.setValue(code); cm.focus(); }
	});

	// ------------------------------------------------------------------ access levels: count, filter, all / none
	var countBadge = $('#procAccessCount'), everyone = $('#sph_global');
	function updateCount() {
		if (!countBadge) return;
		countBadge.textContent = everyone && everyone.checked ? countBadge.getAttribute('data-everyone') : String($$('#procAccessList input[type=checkbox]:checked').length);
	}
	$$('#procAccessList input, #sph_global').forEach(function (i) { i.addEventListener('change', updateCount); });
	var accSearch = $('#procAccessSearch');
	if (accSearch) accSearch.addEventListener('input', function () {
		var q = accSearch.value.trim().toLowerCase();
		$$('#procAccessList [data-text]').forEach(function (l) { l.classList.toggle('d-none', q !== '' && l.getAttribute('data-text').indexOf(q) < 0); });
	});
	$$('[data-access-all]').forEach(function (b) {
		b.addEventListener('click', function () {
			var on = b.getAttribute('data-access-all') === '1';
			$$('#procAccessList [data-text]:not(.d-none) input').forEach(function (i) { i.checked = on; });
			updateCount();
			form.dispatchEvent(new Event('change'));
		});
	});

	// ------------------------------------------------------------------ remember the tab
	var TAB_KEY = 'nunext-proc-tab';
	try {
		var last = localStorage.getItem(TAB_KEY);
		var tb = last && $('#procTabs [data-tab="' + last + '"]');
		if (tb && nuBs.ready() && !form.querySelector('.is-invalid')) nuBs.tab(tb).show();
	} catch (e) { /* ignore */ }
	$$('#procTabs [data-tab]').forEach(function (b) { b.addEventListener('shown.bs.tab', function () { try { localStorage.setItem(TAB_KEY, b.getAttribute('data-tab')); } catch (e) { /* ignore */ } }); });

	// ------------------------------------------------------------------ test run (saved version), with nuOnProcedureRun() first
	var dirty = false;
	form.addEventListener('input', function () { dirty = true; });
	form.addEventListener('change', function () { dirty = true; });
	var result = $('#procResult');

	function showResult(res, mode) {
		result.hidden = false;
		result.innerHTML = '';
		var ok = res && res.ok;
		var box = el('div', {'class': 'alert py-2 mb-0 small ' + (ok ? 'alert-success' : 'alert-danger')});
		box.appendChild(el('div', {'class': 'fw-semibold', text: ok ? M.done : M.failed}));
		(res.errors || []).forEach(function (e) { box.appendChild(el('div', {text: e.message || String(e)})); });
		if (res.output && mode !== 'window') { box.appendChild(el('div', {'class': 'mt-1 fw-semibold', text: M.output})); box.appendChild(el('pre', {'class': 'procResultPre', text: res.output})); }
		if (res.js && res.js.length) { box.appendChild(el('div', {'class': 'mt-1 fw-semibold', text: M.js})); box.appendChild(el('pre', {'class': 'procResultPre', text: res.js.join('\n')})); }
		if (res.debug && res.debug.length) { box.appendChild(el('div', {'class': 'mt-1 fw-semibold', text: M.debug})); box.appendChild(el('pre', {'class': 'procResultPre', text: res.debug.join('\n')})); }
		result.appendChild(box);
	}

	function doRun(mode, props) {
		var w = mode === 'window' ? window.open('', '_blank') : null;
		result.hidden = false;
		result.innerHTML = '';
		result.appendChild(el('div', {'class': 'small text-body-secondary', text: M.running}));
		var fd = new FormData();
		fd.append('_csrf', form.querySelector('input[name=_csrf]').value);
		fd.append('action', 'run');
		fd.append('props', JSON.stringify(props || {}));
		return fetch(D.url, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				showResult(res, mode);
				if (w) { w.document.open(); w.document.write(res.output || (res.errors || []).map(function (e) { return '<p>' + String(e.message).replace(/[<&]/g, function (c) { return c === '<' ? '&lt;' : '&amp;'; }) + '</p>'; }).join('')); w.document.close(); }
			})
			.catch(function (e) { showResult({ok: false, errors: [{message: e.message}]}, mode); if (w) w.close(); });
	}

	function run(mode) {
		if (dirty && M.unsaved && !window.confirm(M.unsaved)) return;
		var props = {};
		var active = $('#sph_javascript_active'), js = editors.sph_javascript ? editors.sph_javascript.getValue() : ($('#sph_javascript') || {}).value || '';
		if (active && active.checked && js.trim() !== '') {
			// nuBuilder: the JS tab's nuOnProcedureRun() runs first; nuSetProperty passes values (#name#), return false cancels,
			// nuRunThisProcedure() runs it later (asynchronous code)
			var saved = {nuSetProperty: window.nuSetProperty, nuGetProperty: window.nuGetProperty, nuRunThisProcedure: window.nuRunThisProcedure};
			window.nuSetProperty = function (k, v) { props[String(k)] = v; };
			window.nuGetProperty = function (k) { return props[String(k)]; };
			window.nuRunThisProcedure = function () { doRun(mode, props); };
			var r;
			try {
				r = (new Function(js + '\n;return typeof nuOnProcedureRun === "function" ? nuOnProcedureRun() : undefined;'))();   // eslint-disable-line no-new-func
			} catch (e) {
				showResult({ok: false, errors: [{message: 'nuOnProcedureRun: ' + e.message}]}, mode);
				return;
			} finally {
				setTimeout(function () { Object.keys(saved).forEach(function (k) { if (saved[k] === undefined) { try { delete window[k]; } catch (x) { window[k] = undefined; } } else window[k] = saved[k]; }); }, 30000);
			}
			if (r === false) { result.hidden = false; result.innerHTML = ''; result.appendChild(el('div', {'class': 'small text-body-secondary', text: M.cancelled})); return; }
		}
		doRun(mode, props);
	}
	$$('[data-run]').forEach(function (b) { b.addEventListener('click', function () { run(b.getAttribute('data-run')); }); });
})();
