/* Form with AI (0.8.403, pages/nuformai.php): copy the prompt, check the pasted answer, create the draft.
   0.8.810: one form or a small app (several forms), Paste button + check on paste, code in the plan, an open draft is continued,
   the app's forms listed after creating with "Publish all". */
(function () {
	'use strict';
	var form = document.getElementById('faForm');
	if (!form) return;
	var L = {};
	try { L = JSON.parse(form.getAttribute('data-labels') || '{}'); } catch (e) { /* keep */ }
	var $ = function (id) { return document.getElementById(id); };
	var csrf = form.querySelector('input[name=_csrf]');
	var reqEl = $('faRequest'), ansEl = $('faAnswer');
	form.addEventListener('submit', function (e) { e.preventDefault(); });

	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
	function fmt(t) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return String(t || '').replace(/%[sd]/g, function () { return a[i++]; }); }
	function kind() { var r = document.querySelector('input[name=faKind]:checked'); return r ? r.value : 'form'; }
	function post(data) {
		var body = new URLSearchParams();
		body.set('_csrf', csrf ? csrf.value : '');
		Object.keys(data).forEach(function (k) {
			if (Array.isArray(data[k])) data[k].forEach(function (v) { body.append(k + '[]', v); });   // tables[] (0.8.633)
			else body.set(k, data[k]);
		});
		return fetch(window.location.href, {method: 'POST', body: body, credentials: 'same-origin', headers: {Accept: 'application/json'}})
			.then(function (r) { return r.json(); });
	}
	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
		return new Promise(function (ok, fail) {
			var ta = document.createElement('textarea');
			ta.value = text; ta.setAttribute('readonly', ''); ta.className = 'position-fixed top-0 start-0 opacity-0';
			document.body.appendChild(ta); ta.select();
			var done = false;
			try { done = document.execCommand('copy'); } catch (e) { done = false; }
			ta.remove();
			if (done) ok(); else fail(new Error('copy'));
		});
	}

	// ---- 0. one form or a small app
	var ph = reqEl.getAttribute('placeholder');
	function syncKind() {
		reqEl.setAttribute('placeholder', kind() === 'app' ? reqEl.getAttribute('data-ph-app') : ph);
		var help = $('faCreateHelp');
		if (help && !help.hasAttribute('data-one')) help.setAttribute('data-one', help.textContent);
		if (help) help.textContent = kind() === 'app' ? help.getAttribute('data-app') : help.getAttribute('data-one');
	}
	Array.prototype.forEach.call(document.querySelectorAll('input[name=faKind]'), function (r) { r.addEventListener('change', syncKind); });
	syncKind();

	// ---- wizard (0.8.843): 1 what · 2 existing tables + copy · 3 answer + check
	function go(n, needReq) {
		if (needReq && !reqEl.value.trim()) { n = 1; reqEl.classList.add('is-invalid'); reqEl.focus(); }
		[1, 2, 3].forEach(function (k) {
			var pane = $('faStep' + k), nav = $('faStepNav' + k);
			if (pane) pane.hidden = k !== n;
			if (nav) { nav.classList.toggle('active', k === n); nav.setAttribute('aria-selected', k === n ? 'true' : 'false'); nav.classList.toggle('nuFaDone', k < n); }
		});
		form.setAttribute('data-step', String(n));
		var f = n === 1 ? reqEl : (n === 3 ? ansEl : null);
		if (f && document.activeElement !== f) { try { f.focus({preventScroll: true}); } catch (e) { /* ignore */ } }
		return n;
	}
	window.nuFormAiStep = go;
	reqEl.addEventListener('input', function () { reqEl.classList.remove('is-invalid'); });
	form.addEventListener('click', function (e) {
		var b = e.target.closest('[data-go], #faSteps [data-step]');
		if (!b || !form.contains(b)) return;
		go(+(b.getAttribute('data-go') || b.getAttribute('data-step')), b.id === 'faNext1');
	});
	function tableMode() { var r = document.querySelector('input[name=faTablesMode]:checked'); return r ? r.value : 'none'; }
	Array.prototype.forEach.call(document.querySelectorAll('input[name=faTablesMode]'), function (r) {
		r.addEventListener('change', function () {
			$('faPickBox').hidden = tableMode() !== 'pick';
			var ts = $('faTables') && $('faTables').tomselect;
			if (tableMode() === 'pick' && ts) setTimeout(function () { ts.focus(); }, 50);
		});
	});
	function chosenTables() {
		var m = tableMode();
		if (m === 'all') return [];
		if (m === 'pick') {
			var tsel = $('faTables'), picked = tsel ? Array.prototype.filter.call(tsel.options, function (o) { return o.selected; }).map(function (o) { return o.value; }) : [];
			if (picked.length) return picked;
		}
		return ['__none__'];
	}

	// ---- 1. prompt
	$('faCopy').addEventListener('click', function () {
		var b = this, msg = $('faCopyMsg');
		b.disabled = true; msg.textContent = '';
		var tables = chosenTables();
		post({action: 'prompt', request: reqEl.value, tables: tables, kind: kind()}).then(function (d) {
			if (!d.ok) { msg.textContent = d.error || ''; msg.className = 'small text-danger'; return; }
			$('faPrompt').value = d.text;
			$('faPromptBox').hidden = false;
			if (!$('faNote').value) $('faNote').value = reqEl.value.split('\n')[0].slice(0, 200);
			return copy(d.text).then(function () { msg.textContent = L.fai_copied; msg.className = 'small text-success'; setTimeout(function () { go(3); }, 900); },
				function () { msg.textContent = L.fai_copy_failed; msg.className = 'small text-warning'; $('faPromptBox').open = true; });
		}).catch(function (e) { msg.textContent = e.message; msg.className = 'small text-danger'; })
			.then(function () { b.disabled = false; });
	});

	// ---- 1a. "Ask AI" (0.8.1099, assets/js/nuaiask.js): the prompt goes to a configured AI service, the answer into step 3
	if (window.NuAiAsk && $('faAsk')) window.NuAiAsk.mount($('faAsk'), {
		prompt: function () {
			if (!reqEl.value.trim()) { go(1, true); return ''; }
			return post({action: 'prompt', request: reqEl.value, tables: chosenTables(), kind: kind()}).then(function (d) {
				if (!d.ok) { $('faCopyMsg').textContent = d.error || ''; $('faCopyMsg').className = 'small text-danger'; return ''; }
				$('faPrompt').value = d.text; $('faPromptBox').hidden = false;
				if (!$('faNote').value) $('faNote').value = reqEl.value.split('\n')[0].slice(0, 200);
				return d.text;
			});
		},
		answer: function (text) { ansEl.value = text; },
		check: function () { return check().then(function (d) { go(3); return d && d.errors ? d.errors : []; }); }
	});

	// ---- 1b. context file (0.8.961): functions, hash variables, guide, example forms – attached to the chat with the prompt
	function download(blob, name) {
		var url = URL.createObjectURL(blob), a = document.createElement('a');
		a.href = url; a.download = name; a.className = 'd-none';
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
	}
	var ctxBox = $('faCtx');
	if (ctxBox) ctxBox.addEventListener('click', function (e) {
		var b = e.target.closest('[data-nu-ctx]');
		if (!b) return;
		var msg = $('faCopyMsg'), full = $('faCtxFull');
		var body = new URLSearchParams();
		body.set('_csrf', csrf ? csrf.value : ''); body.set('action', 'context'); body.set('kind', kind());
		body.set('fmt', b.getAttribute('data-nu-ctx')); body.set('full', full && full.checked ? '1' : '0');
		chosenTables().forEach(function (t) { body.append('tables[]', t); });
		msg.textContent = '';
		fetch(window.location.href, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			var name = r.headers.get('X-Nu-File-Name') || 'nunext-context.md';
			return r.blob().then(function (bl) { download(bl, name); msg.textContent = L.fai_ctx_done; msg.className = 'small text-success'; });
		}).catch(function (x) { msg.textContent = fmt(L.fai_ctx_failed, x.message); msg.className = 'small text-danger'; });
	});

	// ---- 2. check
	function list(title, cls, items) {
		if (!items || !items.length) return '';
		return '<div class="alert ' + cls + ' py-2"><div class="fw-semibold mb-1">' + esc(title) + '</div><ul class="mb-0 ps-3">'
			+ items.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
	}
	function planRows(p) {
		var rows = [];
		if (p.new_table) rows.push(fmt(L.fai_plan_new_table, p.new_table.table, p.new_table.pk));
		if (p.columns && p.columns.length) rows.push(fmt(L.fai_plan_columns, p.columns.map(function (c) { return c.name + ' (' + c.type + (c.length ? ' ' + c.length : '') + ')'; }).join(', ')));
		if (p.form && p.form.length) rows.push(fmt(L.fai_plan_form, p.form.join(', ')));
		if (p.added && p.added.length) rows.push(fmt(L.fai_plan_added, p.added.join(', ')));
		if (p.changed && p.changed.length) rows.push(fmt(L.fai_plan_changed, p.changed.map(function (c) { return c.id + ' (' + c.fields.join(', ') + ')'; }).join('; ')));
		if (p.removed && p.removed.length) rows.push(fmt(L.fai_plan_removed, p.removed.join(', ')));
		if (p.moved) rows.push(L.fai_plan_moved);
		if (p.tabs) rows.push(fmt(L.fai_plan_tabs, p.tabs));
		if (p.browse) rows.push(fmt(L.fai_plan_browse, p.browse));
		if (p.code && p.code.length) rows.push(fmt(L.fai_plan_code, p.code.join(', ')));
		return rows;
	}
	function planHtml(p) {
		var rows = planRows(p);
		if (!rows.length) rows.push(L.fai_plan_none);
		return '<div class="fw-semibold mb-1">' + esc(L.fai_plan) + '</div><ul class="ps-3" id="faPlanList">' + rows.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>';
	}
	function appHtml(p) {
		var h = '<div class="fw-semibold mb-2">' + esc(fmt(L.fai_plan_app, p.forms.length)) + (p.name ? ' – ' + esc(p.name) : '') + '</div><div id="faPlanList">';
		p.forms.forEach(function (f) {
			var rows = planRows(f).filter(function (r) { return r.indexOf(fmt(L.fai_plan_added, '')) !== 0; });
			rows.unshift(fmt(L.fai_plan_objects, (f.added || []).length));
			h += '<div class="border rounded p-2 mb-2 faAppForm" data-code="' + esc(f.form_code) + '"><div class="fw-semibold">' + esc(f.form_code) + ' – ' + esc(f.description)
				+ ' <span class="badge text-bg-light border fw-normal">' + esc(L['form_type_' + f.type] || f.type) + '</span></div><ul class="ps-3 mb-0">'
				+ rows.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul></div>';
		});
		return h + '</div>';
	}
	function hasDdl(d) {
		if (!d.ok || !d.plan) return false;
		if (d.app) return d.plan.forms.some(function (f) { return f.new_table || (f.columns && f.columns.length); });
		return !!(d.plan.new_table || (d.plan.columns && d.plan.columns.length));
	}
	function check() {
		var b = $('faCheck');
		b.disabled = true;
		$('faMade').hidden = true;
		return post({action: 'check', answer: ansEl.value}).then(function (d) {
			$('faResult').hidden = false;
			$('faMessages').innerHTML = (d.ok ? '<div class="alert alert-success py-2" id="faOk"><i class="bi bi-check-circle me-1"></i>' + esc(L.fai_ok)
				+ (d.into_draft ? ' ' + esc(L.fai_into_draft) : '') + '</div>' : '')
				+ list(L.fai_errors, 'alert-danger', d.errors) + list(L.fai_warnings, 'alert-warning', d.warnings);
			$('faPlan').innerHTML = d.ok && d.plan ? (d.app ? appHtml(d.plan) : planHtml(d.plan)) : '';
			$('faCreateBox').hidden = !d.ok;
			var c = $('faCreate');
			if (c && d.app) c.lastChild.textContent = L.fai_create_app;
			$('faDdlBox').hidden = !hasDdl(d);
			$('faDdl').checked = false;
			$('faResult').scrollIntoView({block: 'start', behavior: 'smooth'});
			return d;
		}).catch(function (e) { $('faResult').hidden = false; $('faMessages').innerHTML = list(L.fai_errors, 'alert-danger', [e.message]); })
			.then(function (d) { b.disabled = false; return d; });
	}
	$('faCheck').addEventListener('click', check);
	ansEl.addEventListener('input', function () { $('faCreateBox').hidden = true; });
	ansEl.addEventListener('paste', function () { setTimeout(function () { if (ansEl.value.trim() !== '') check(); }, 0); });   // check at once
	$('faPaste').addEventListener('click', function () {
		if (!navigator.clipboard || !navigator.clipboard.readText) { ansEl.focus(); $('faCopyMsg').textContent = L.fai_paste_failed; return; }
		navigator.clipboard.readText().then(function (txt) { ansEl.value = txt; if (txt.trim() !== '') check(); },
			function () { ansEl.focus(); $('faMessages').innerHTML = ''; $('faResult').hidden = false; $('faMessages').innerHTML = list(L.fai_warnings, 'alert-warning', [L.fai_paste_failed]); });
	});

	// ---- 3. create the draft / the app
	function made(d) {
		var box = $('faMade');
		var h = '<div class="alert alert-success py-2 mt-3" id="faMadeOk"><i class="bi bi-check-circle me-1"></i>' + esc(fmt(L.fai_made, d.forms.length)) + '</div><ul class="list-group mb-2" id="faMadeList">';
		d.forms.forEach(function (f) {
			h += '<li class="list-group-item d-flex align-items-center gap-2"><span class="flex-grow-1"><strong>' + esc(f.description) + '</strong> <code class="small">' + esc(f.code) + '</code>'
				+ ' <span class="badge bg-warning-subtle text-warning-emphasis faMadeSt" data-id="' + esc(f.id) + '">' + esc(L.builder_status_0) + '</span></span>'
				+ '<a class="btn btn-sm btn-outline-secondary" href="' + esc(f.url) + '">' + esc(L.fai_made_open) + '</a></li>';
		});
		h += '</ul>';
		if (d.can_publish) h += '<button type="button" class="btn btn-primary" id="faPublishAll"><i class="bi bi-rocket-takeoff me-1"></i>' + esc(L.fai_publish_all) + '</button>';
		box.innerHTML = h;
		box.hidden = false;
		$('faCreateBox').hidden = true;
		var pub = $('faPublishAll');
		if (pub) pub.addEventListener('click', function () {
			pub.disabled = true;
			post({action: 'publish_all', forms: d.forms.map(function (f) { return f.id; })}).then(function (r) {
				if (!r.ok) { $('faMessages').innerHTML = list(L.fai_errors, 'alert-danger', r.errors || ['?']); pub.disabled = false; return; }
				Array.prototype.forEach.call(box.querySelectorAll('.faMadeSt'), function (b) { b.remove(); });
				pub.replaceWith(Object.assign(document.createElement('div'), {className: 'small text-success', id: 'faPublished', textContent: fmt(L.fai_published, r.published)}));
			}, function () { pub.disabled = false; });
		});
		box.scrollIntoView({block: 'start', behavior: 'smooth'});
	}
	var create = $('faCreate');
	if (create) create.addEventListener('click', function () {
		var ddlBox = $('faDdlBox');
		if (!ddlBox.hidden && !$('faDdl').checked) { $('faDdl').focus(); ddlBox.classList.add('text-danger'); return; }
		create.disabled = true;
		var html = create.innerHTML;
		create.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + esc(L.fai_creating);
		post({action: 'create', answer: ansEl.value, ddl_ok: $('faDdl').checked ? '1' : '0', note: $('faNote').value}).then(function (d) {
			create.disabled = false; create.innerHTML = html;
			if (d.ok && d.app) { made(d); return; }
			if (d.ok && d.url) { window.location.href = d.url; return; }
			$('faMessages').innerHTML = list(L.fai_errors, 'alert-danger', d.errors || ['?']);
		}).catch(function (e) {
			$('faMessages').innerHTML = list(L.fai_errors, 'alert-danger', [e.message]);
			create.disabled = false; create.innerHTML = html;
		});
	});
})();
