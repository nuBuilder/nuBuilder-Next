/* global nuBs */
/* Reports with AI (0.8.1097, core/nureportspec.php): the dialogue #raiDlg on the report page and in the report designer –
   describe → copy the prompt (+ context file) → paste the answer → check (plan) → preview PDF → apply. Posts to ?p=reportai&id=. */
(function () {
	'use strict';
	var dlg = document.getElementById('raiDlg');
	if (!dlg) return;
	var D = {};
	try { D = JSON.parse(dlg.getAttribute('data-rai') || '{}'); } catch (e) { /* keep */ }
	var M = D.msg || {};
	var $ = function (id) { return document.getElementById(id); };
	var reqEl = $('raiReq'), ansEl = $('raiAns'), opt = {back: '', dirty: null};

	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
	function allowSql() { var c = $('raiSql'); return c && c.checked ? '1' : '0'; }
	function tables() { var s = $('raiTables'); return s ? Array.prototype.filter.call(s.options, function (o) { return o.selected; }).map(function (o) { return o.value; }) : []; }
	function body(data) {
		var b = new URLSearchParams();
		b.set('_csrf', D.csrf || '');
		Object.keys(data).forEach(function (k) {
			if (Array.isArray(data[k])) data[k].forEach(function (v) { b.append(k + '[]', v); }); else b.set(k, data[k]);
		});
		return b;
	}
	function post(data) {
		return fetch(D.url, {method: 'POST', body: body(data), credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(function (r) { return r.json(); });
	}
	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
		return new Promise(function (ok, fail) {
			var ta = document.createElement('textarea');
			ta.value = text; ta.setAttribute('readonly', ''); ta.className = 'position-fixed top-0 start-0 opacity-0';
			dlg.appendChild(ta); ta.select();
			var done = false;
			try { done = document.execCommand('copy'); } catch (e) { done = false; }
			ta.remove();
			if (done) ok(); else fail(new Error('copy'));
		});
	}
	function say(text, bad) { var m = $('raiCopyMsg'); m.textContent = text || ''; m.classList.toggle('text-danger', !!bad); }

	// ---- 1. the prompt
	if ($('raiSql')) $('raiSql').addEventListener('change', function () { $('raiTablesBox').hidden = !this.checked; $('raiPromptBox').hidden = true; });
	reqEl.addEventListener('input', function () { reqEl.classList.remove('is-invalid'); });
	$('raiCopy').addEventListener('click', function () {
		if (!reqEl.value.trim()) { reqEl.classList.add('is-invalid'); reqEl.focus(); return; }
		say('');
		post({action: 'prompt', request: reqEl.value, allow_sql: allowSql(), tables: tables()}).then(function (r) {
			if (!r.ok) { say(r.error || '?', true); return; }
			$('raiPrompt').value = r.text;
			$('raiPromptBox').hidden = false;
			return copy(r.text).then(function () { say(M.fai_copied); ansEl.focus(); }, function () { $('raiPromptBox').open = true; say(M.fai_copy_failed, true); });
		}).catch(function () { say('?', true); });
	});
	// "Ask AI" (0.8.1099, assets/js/nuaiask.js): the same prompt goes to a configured AI service, its answer into the answer box
	if (window.NuAiAsk && $('raiAsk')) window.NuAiAsk.mount($('raiAsk'), {
		prompt: function () {
			if (!reqEl.value.trim()) { reqEl.classList.add('is-invalid'); reqEl.focus(); return ''; }
			return post({action: 'prompt', request: reqEl.value, allow_sql: allowSql(), tables: tables()}).then(function (r) {
				if (!r.ok) { say(r.error || '?', true); return ''; }
				$('raiPrompt').value = r.text; $('raiPromptBox').hidden = false;
				return r.text;
			});
		},
		answer: function (text) { ansEl.value = text; },
		check: function () { return check().then(function (r) { return r && r.errors ? r.errors : []; }); }
	});
	$('raiCtx').addEventListener('click', function () {
		var samples = $('raiCtxSamples') && $('raiCtxSamples').checked ? '1' : '0';
		fetch(D.url, {method: 'POST', body: body({action: 'context', samples: samples}), credentials: 'same-origin'}).then(function (r) {
			var name = r.headers.get('X-Nu-File-Name') || 'nunext-report-context.md';
			return r.blob().then(function (b) {
				var a = document.createElement('a');
				a.href = URL.createObjectURL(b); a.download = name;
				dlg.appendChild(a); a.click(); a.remove();
				setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
			});
		}).catch(function () { say('?', true); });
	});

	// ---- 2. the answer
	function list(cls, title, items) {
		return '<div class="alert alert-' + cls + ' py-2 mb-2"><div class="fw-semibold">' + esc(title) + '</div><ul class="mb-0 ps-3">'
			+ items.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
	}
	function plan(p) {
		if (!p || !p.bands) return '';
		var h = '<table class="table table-sm align-middle mb-2" id="raiPlanTable"><tbody>';
		h += '<tr><th scope="row">' + esc(M.rai_plan_page) + '</th><td>' + esc(p.paper) + ' · ' + esc(p.orientation === 'landscape' ? M.rpt_landscape : M.rpt_portrait) + '</td></tr>';
		h += '<tr><th scope="row">' + esc(M.rai_plan_groups) + '</th><td>' + (p.groups.length ? p.groups.map(function (g) { return '<code>' + esc(g) + '</code>'; }).join(' › ') : esc(M.rai_plan_none)) + '</td></tr>';
		h += '<tr><th scope="row">' + esc(M.rai_plan_bands) + '</th><td>' + p.bands.map(function (b) {
			return '<span class="badge bg-secondary-subtle text-secondary-emphasis me-1 mb-1">' + esc(b.band) + ' · ' + esc(b.height) + ' mm · ' + esc(b.objects) + '</span>';
		}).join('') + '</td></tr>';
		h += '<tr><th scope="row">' + esc(M.rai_plan_objects) + '</th><td><span id="raiPlanObjects">' + esc(p.objects) + '</span>'
			+ (p.had ? ' – ' + esc(String(M.rai_plan_changes || '').replace('%1', p.kept).replace('%2', p.added).replace('%3', p.removed)) : ' – ' + esc(M.rai_plan_new)) + '</td></tr>';
		if (p.source) {
			h += '<tr><th scope="row">' + esc(M.rai_plan_source) + '</th><td><code>' + esc(p.source.code) + '</code> – ' + esc(p.source.description)
				+ '<pre class="small bg-body-tertiary border rounded p-2 mt-1 mb-1 text-wrap" id="raiPlanSql">' + esc(p.source.sql) + '</pre>'
				+ '<div class="text-body-secondary">' + esc(M.rai_sql_ok) + ' ' + p.source.columns.map(function (c) { return '<code>' + esc(c) + '</code>'; }).join(', ') + '</div></td></tr>';
		}
		return h + '</tbody></table>';
	}
	function check() {
		$('raiResult').hidden = false;
		$('raiFoot').hidden = true;
		$('raiMsgs').innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
		$('raiPlan').innerHTML = '';
		return post({action: 'check', answer: ansEl.value, allow_sql: allowSql()}).then(function (r) {
			var h = '';
			if (r.errors && r.errors.length) h += list('danger', M.fai_errors, r.errors);
			if (r.warnings && r.warnings.length) h += list('warning', M.fai_warnings, r.warnings);
			if (r.ok) h = '<div class="alert alert-success py-2 mb-2" id="raiOk"><i class="bi bi-check-circle me-1"></i>' + esc(M.fai_ok) + '</div>' + h;
			$('raiMsgs').innerHTML = h;
			$('raiPlan').innerHTML = r.ok ? plan(r.plan) : '';
			$('raiFoot').hidden = !r.ok;
			return r;
		}).catch(function () { $('raiMsgs').innerHTML = list('danger', M.fai_errors, ['?']); });
	}
	$('raiCheck').addEventListener('click', check);
	ansEl.addEventListener('input', function () { $('raiFoot').hidden = true; });
	ansEl.addEventListener('paste', function () { setTimeout(function () { if (ansEl.value.trim()) check(); }, 0); });
	$('raiPaste').addEventListener('click', function () {
		if (!navigator.clipboard || !navigator.clipboard.readText) { say(M.fai_paste_failed, true); ansEl.focus(); return; }
		navigator.clipboard.readText().then(function (t) { ansEl.value = t; if (t.trim()) check(); }, function () { say(M.fai_paste_failed, true); ansEl.focus(); });
	});

	// ---- 3. preview and apply
	$('raiPreview').addEventListener('click', function () {
		var f = $('raiPreviewForm');
		f.elements.answer.value = ansEl.value;
		f.elements.allow_sql.value = allowSql();
		f.submit();
	});
	if ($('raiApply')) $('raiApply').addEventListener('click', function () {
		var b = this;
		b.disabled = true;
		post({action: 'apply', answer: ansEl.value, allow_sql: allowSql(), note: reqEl.value.slice(0, 200), back: opt.back}).then(function (r) {
			if (r.ok) {
				if (typeof opt.applied === 'function') opt.applied();
				window.location.href = r.url;
				if (r.url.split('#')[0] === window.location.href.split('#')[0]) window.location.reload();
				return;
			}
			b.disabled = false;
			$('raiMsgs').innerHTML = list('danger', M.fai_errors, r.errors || ['?']);
			$('raiFoot').hidden = true;
		}).catch(function () { b.disabled = false; });
	});

	/** Opens the dialogue. o: {back: 'designer', dirty: function → true when the page has unsaved changes, applied: function}. */
	window.nuReportAiOpen = function (o) {
		opt = o || {back: ''};
		if (typeof opt.dirty === 'function' && opt.dirty()) { if (window.nuToast) window.nuToast(M.rai_dirty, 'warning'); else window.alert(M.rai_dirty); return; }
		nuBs.modal(dlg).show();
	};
	dlg.addEventListener('shown.bs.modal', function () { if (!reqEl.value) reqEl.focus(); });
	Array.prototype.forEach.call(document.querySelectorAll('[data-rai-open]'), function (b) { b.addEventListener('click', function () { window.nuReportAiOpen({back: ''}); }); });
	// History tab: put an earlier layout back (the form asks first – form[data-confirm])
	Array.prototype.forEach.call(document.querySelectorAll('.rptVerRestore'), function (b) {
		b.addEventListener('click', function () {
			var f = $('rptRestoreForm');
			if (!f) return;
			f.elements.version.value = b.getAttribute('data-version');
			if (f.requestSubmit) f.requestSubmit(); else f.submit();
		});
	});
	if (D.open) window.nuReportAiOpen({back: ''});
})();
