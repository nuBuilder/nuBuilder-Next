/* Form with AI (0.8.403, pages/nuformai.php): copy the prompt, check the pasted answer, create the draft. */
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

	// ---- 1. prompt
	$('faCopy').addEventListener('click', function () {
		var b = this, msg = $('faCopyMsg');
		b.disabled = true; msg.textContent = '';
		var tsel = $('faTables'), tables = tsel ? Array.prototype.filter.call(tsel.options, function (o) { return o.selected; }).map(function (o) { return o.value; }) : [];
		post({action: 'prompt', request: reqEl.value, tables: tables}).then(function (d) {
			if (!d.ok) { msg.textContent = d.error || ''; msg.className = 'small text-danger'; return; }
			$('faPrompt').value = d.text;
			$('faPromptBox').hidden = false;
			if (!$('faNote').value) $('faNote').value = reqEl.value.split('\n')[0].slice(0, 200);
			return copy(d.text).then(function () { msg.textContent = L.fai_copied; msg.className = 'small text-success'; },
				function () { msg.textContent = L.fai_copy_failed; msg.className = 'small text-warning'; $('faPromptBox').open = true; });
		}).catch(function (e) { msg.textContent = e.message; msg.className = 'small text-danger'; })
			.then(function () { b.disabled = false; });
	});

	// ---- 2. check
	function list(title, cls, items) {
		if (!items || !items.length) return '';
		return '<div class="alert ' + cls + ' py-2"><div class="fw-semibold mb-1">' + esc(title) + '</div><ul class="mb-0 ps-3">'
			+ items.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
	}
	function planHtml(p) {
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
		if (!rows.length) rows.push(L.fai_plan_none);
		return '<div class="fw-semibold mb-1">' + esc(L.fai_plan) + '</div><ul class="ps-3" id="faPlanList">' + rows.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>';
	}
	$('faCheck').addEventListener('click', function () {
		var b = this;
		b.disabled = true;
		post({action: 'check', answer: ansEl.value}).then(function (d) {
			$('faResult').hidden = false;
			$('faMessages').innerHTML = (d.ok ? '<div class="alert alert-success py-2" id="faOk"><i class="bi bi-check-circle me-1"></i>' + esc(L.fai_ok) + '</div>' : '')
				+ list(L.fai_errors, 'alert-danger', d.errors) + list(L.fai_warnings, 'alert-warning', d.warnings);
			$('faPlan').innerHTML = d.ok && d.plan ? planHtml(d.plan) : '';
			$('faCreateBox').hidden = !d.ok;
			var ddl = !!(d.ok && d.plan && (d.plan.new_table || (d.plan.columns && d.plan.columns.length)));
			$('faDdlBox').hidden = !ddl;
			$('faDdl').checked = false;
			$('faResult').scrollIntoView({block: 'start', behavior: 'smooth'});
		}).catch(function (e) { $('faResult').hidden = false; $('faMessages').innerHTML = list(L.fai_errors, 'alert-danger', [e.message]); })
			.then(function () { b.disabled = false; });
	});
	ansEl.addEventListener('input', function () { $('faCreateBox').hidden = true; });

	// ---- 3. create the draft
	var create = $('faCreate');
	if (create) create.addEventListener('click', function () {
		var ddlBox = $('faDdlBox');
		if (!ddlBox.hidden && !$('faDdl').checked) { $('faDdl').focus(); ddlBox.classList.add('text-danger'); return; }
		create.disabled = true;
		var html = create.innerHTML;
		create.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + esc(L.fai_creating);
		post({action: 'create', answer: ansEl.value, ddl_ok: $('faDdl').checked ? '1' : '0', note: $('faNote').value}).then(function (d) {
			if (d.ok && d.url) { window.location.href = d.url; return; }
			$('faMessages').innerHTML = list(L.fai_errors, 'alert-danger', d.errors || ['?']);
			create.disabled = false; create.innerHTML = html;
		}).catch(function (e) {
			$('faMessages').innerHTML = list(L.fai_errors, 'alert-danger', [e.message]);
			create.disabled = false; create.innerHTML = html;
		});
	});
})();
