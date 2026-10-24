/*
 * E-mail template editor: placeholders of the chosen form (#object_id#) are inserted at the cursor of the last
 * focused field (To, Cc, Bcc, Subject, Body), formatting buttons for the body, preview and test mail.
 */
(function () {
	'use strict';
	var dataEl = document.getElementById('emtData');
	if (!dataEl) return;
	var D = JSON.parse(dataEl.textContent);
	var form = document.getElementById('emtForm');
	var body = document.getElementById('emt_body');
	var target = body;
	var list = document.getElementById('emtFields');
	var search = document.getElementById('emtFieldSearch');
	var formSel = document.getElementById('emt_form_id');
	var fields = [];

	// code, description and recipients fold away (0.8.432): the state is remembered in this browser; a field with an error or a required
	// field the browser complains about opens it; the summary line follows the fields
	var fold = document.getElementById('emtHead'), headInfo = document.getElementById('emtHeadInfo');
	if (fold) {
		var KEY = 'nunext-emt-head-open';
		try { if (!fold.hasAttribute('data-nu-force-open')) { var st = localStorage.getItem(KEY); if (st !== null) fold.open = st === '1'; } } catch (e) { /* no storage */ }
		fold.addEventListener('toggle', function () { try { localStorage.setItem(KEY, fold.open ? '1' : '0'); } catch (e) { /* no storage */ } });
		fold.addEventListener('invalid', function () { fold.open = true; }, true);
		var info = function () {
			var val = function (id) { var f = document.getElementById(id); return f ? f.value.trim() : ''; };
			var to = val('emt_to');
			headInfo.textContent = [val('emt_code'), val('emt_description'), to ? (D.toLabel || 'To') + ': ' + to : ''].filter(Boolean).join(' · ');
		};
		['emt_code', 'emt_description', 'emt_to'].forEach(function (id) { var f = document.getElementById(id); if (f) f.addEventListener('input', info); });
	}

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') e.textContent = attrs[k];
			else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
			else e.setAttribute(k, attrs[k]);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}

	// the field that receives placeholders: the last focused one
	document.querySelectorAll('.emtTarget').forEach(function (f) {
		f.addEventListener('focus', function () {
			target = f;
			document.querySelectorAll('.emtTarget').forEach(function (x) { x.classList.toggle('emtActive', x === f); });
		});
	});
	body.classList.add('emtActive');

	function insert(text, f) {
		f = f || target;
		if (f.disabled || f.readOnly) return;
		var a = f.selectionStart === null ? f.value.length : f.selectionStart, b = f.selectionEnd === null ? a : f.selectionEnd;
		f.value = f.value.slice(0, a) + text + f.value.slice(b);
		f.focus();
		f.setSelectionRange(a + text.length, a + text.length);
		f.dispatchEvent(new Event('input', {bubbles: true}));
	}

	// ------------------------------------------------------------------ placeholders
	// into the body a form field comes with its label: <b>Field Id: </b>#field_id# (subject, to, cc …: only #field_id#)
	function htmlText(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
	function placeholder(id, label, isField) {
		var ph = '#' + id + '#';
		if (!isField || target !== body) return ph;
		label = String(label || '').replace(/\s+/g, ' ').trim().replace(/\s*:$/, '');
		return label ? '<b>' + htmlText(label) + ': </b>' + ph : ph;
	}
	function renderFields() {
		var q = (search.value || '').toLowerCase();
		list.innerHTML = '';
		function item(id, label, meta, isField) {
			if (q && (id + ' ' + label).toLowerCase().indexOf(q) < 0) return;
			list.appendChild(el('button', {type: 'button', 'class': 'list-group-item list-group-item-action emtField', role: 'listitem',
				title: D.labels.insert, onclick: function () { insert(placeholder(id, label, isField)); }}, [
				el('span', {'class': 'emtFieldLabel', text: label}),
				el('code', {'class': 'emtFieldId', text: '#' + id + '#'}),
				meta ? el('span', {'class': 'emtFieldMeta', text: meta}) : null
			]));
		}
		if (!fields.length) list.appendChild(el('div', {'class': 'list-group-item small text-body-secondary', text: D.labels.no_fields}));
		fields.forEach(function (f) { item(f.id, f.label, f.tab ? f.tab + ' · ' + f.type : f.type, true); });
		list.appendChild(el('div', {'class': 'list-group-item small fw-semibold text-body-secondary bg-body-tertiary', text: D.labels.system}));
		D.system.forEach(function (s) { item(s, s.toLowerCase().replace(/_/g, ' '), ''); });
	}
	function loadFields() {
		var f = formSel.value;
		if (!f) { fields = []; renderFields(); return; }
		fetch(D.fieldsUrl + encodeURIComponent(f), {credentials: 'same-origin'}).then(function (r) { return r.json(); })
			.then(function (j) { fields = j.fields || []; renderFields(); }, function () { fields = []; renderFields(); });
	}
	formSel.addEventListener('change', loadFields);
	search.addEventListener('input', renderFields);
	loadFields();

	// ------------------------------------------------------------------ formatting (body)
	document.querySelectorAll('[data-wrap]').forEach(function (b) {
		b.addEventListener('click', function () {
			var tag = b.getAttribute('data-wrap'), a = body.selectionStart, z = body.selectionEnd, sel = body.value.slice(a, z);
			var open = '<' + tag + '>', close = '</' + tag + '>';
			if (tag === 'a') { open = '<a href="' + (/^https?:\/\//.test(sel) ? sel : 'https://') + '">'; close = '</a>'; }
			if (tag === 'ul') { open = '<ul>\n<li>'; close = '</li>\n</ul>'; sel = sel.split('\n').join('</li>\n<li>'); }
			body.setRangeText(open + sel + close, a, z, 'select');
			body.focus();
			body.dispatchEvent(new Event('input', {bubbles: true}));
		});
	});

	// ------------------------------------------------------------------ preview / test
	function post(action, extra) {
		var fd = new FormData(form);
		fd.set('action', action);
		Object.keys(extra || {}).forEach(function (k) { fd.set(k, extra[k]); });
		if (formSel.disabled) fd.set('emt_form_id', formSel.value);
		return fetch(form.getAttribute('action') || window.location.href, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-CSRF-Token': D.csrf}})
			.then(function (r) { return r.json(); });
	}
	var head = document.getElementById('emtPreviewHead'), frame = document.getElementById('emtPreview'), rec = document.getElementById('emtRecord');
	function preview() {
		post('preview', {record: rec.value}).then(function (j) {
			head.innerHTML = '';
			[['To', j.to], ['Cc', j.cc], ['Bcc', j.bcc], ['', j.subject]].forEach(function (p) {
				if (!p[1]) return;
				head.appendChild(el('div', {}, [p[0] ? el('span', {'class': 'text-body-secondary me-2', text: p[0] + ':'}) : null, el(p[0] ? 'span' : 'strong', {text: p[1]})]));
			});
			if (rec.value && !j.found) head.appendChild(el('div', {'class': 'text-warning-emphasis', text: D.labels.not_found}));
			frame.srcdoc = '<!doctype html><meta charset="utf-8"><body>' + (j.body || '') + '</body>';
		});
	}
	document.getElementById('emtPreviewBtn').addEventListener('click', preview);
	var timer;
	form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(preview, 500); });
	rec.addEventListener('change', preview);
	preview();

	var res = document.getElementById('emtTestResult');
	var emtTest = document.getElementById('emtTestBtn');
	if (emtTest) emtTest.addEventListener('click', function () {
		var b = this; b.disabled = true; res.textContent = '…'; res.className = 'small';
		post('test', {record: rec.value, test_to: document.getElementById('emtTestTo').value}).then(function (j) {
			b.disabled = false;
			res.textContent = j.ok ? D.labels.sent : j.error;
			res.className = 'small ' + (j.ok ? 'text-success' : 'text-danger');
		}, function () { b.disabled = false; res.textContent = 'Error'; res.className = 'small text-danger'; });
	});

	// delete needs a confirmation
	document.querySelectorAll('[data-confirm-submit]').forEach(function (b) {
		b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm-submit'))) e.preventDefault(); });
	});
})();
