// Form builder of a page form (form type "page", 0.8.387 – pages/nubuilder_page.php).
(function () {
	'use strict';
	var $ = function (s) { return document.querySelector(s); };
	var form = $('#pgForm');
	var mode = $('#pg_mode');
	var settings = $('#pg_settings');
	var jsonErr = $('#pgJsonError');

	function applyMode() {
		document.querySelectorAll('.pgIframeOnly').forEach(function (e) { e.classList.toggle('d-none', !mode || mode.value !== 'iframe'); });
	}
	if (mode) { mode.addEventListener('change', applyMode); applyMode(); }

	// renaming the code: the folder must be renamed by FTP too
	var code = $('#pg_code');
	var warn = $('#pgCodeWarn');
	if (code && warn) {
		code.addEventListener('input', function () {
			var changed = code.value.trim() !== code.getAttribute('data-old');
			warn.textContent = changed ? code.getAttribute('data-warn') : '';
			warn.classList.toggle('d-none', !changed);
		});
	}

	function checkJson() {
		if (!settings) return true;
		var txt = settings.value.trim() || '{}';
		try {
			var v = JSON.parse(txt);
			if (v === null || typeof v !== 'object') throw new Error('object expected');
			settings.classList.remove('is-invalid');
			return v;
		} catch (e) {
			settings.classList.add('is-invalid');
			if (jsonErr) jsonErr.textContent = ($('#pgPretty') ? $('#pgPretty').getAttribute('data-bad') : '') + e.message;
			return false;
		}
	}
	var pretty = $('#pgPretty');
	if (pretty) pretty.addEventListener('click', function () {
		var v = checkJson();
		if (v !== false) {
			settings.value = JSON.stringify(v, null, 2);
			settings.dispatchEvent(new Event('input', {bubbles: true}));
		}
	});
	if (settings) settings.addEventListener('blur', checkJson);

	// keys the page reads (0.8.391): set / not set (default) while typing, and keys set that the page never reads
	var keysBox = $('#pgKeys');
	function has(obj, path) {
		var v = obj;
		var parts = path.split('.');
		for (var i = 0; i < parts.length; i++) {
			if (v === null || typeof v !== 'object' || !Object.prototype.hasOwnProperty.call(v, parts[i])) return false;
			v = v[parts[i]];
		}
		return true;
	}
	function updateKeys() {
		if (!keysBox || !settings) return;
		var obj;
		try { obj = JSON.parse(settings.value.trim() || '{}'); } catch (e) { return; }   // invalid JSON: keep the last state
		if (obj === null || typeof obj !== 'object') return;
		var firsts = {};
		keysBox.querySelectorAll('.pgKey').forEach(function (tr) {
			var key = tr.getAttribute('data-key');
			firsts[key.split('.')[0]] = 1;
			var set = has(obj, key);
			var b = tr.querySelector('.pgKeyState');
			b.textContent = keysBox.getAttribute(set ? 'data-set' : 'data-unset');
			b.className = 'badge pgKeyState ' + (set ? 'text-bg-success' : 'text-bg-light border');
		});
		var unusedBox = $('#pgKeysUnused');
		var unused = keysBox.getAttribute('data-all') === '1' || Array.isArray(obj) ? [] : Object.keys(obj).filter(function (k) { return !firsts[k]; });
		unusedBox.textContent = unused.length ? keysBox.getAttribute('data-unused').replace('%s', unused.join(', ')) : '';
		unusedBox.classList.toggle('d-none', !unused.length);
	}
	if (settings && keysBox) { settings.addEventListener('input', updateKeys); updateKeys(); }

	// examples of the settings JSON (0.8.394): from the page's keys, its settings.example.json and built-in samples
	var examples = $('#pgExamples');
	if (examples && settings) examples.querySelectorAll('[data-example]').forEach(function (item) {
		item.addEventListener('click', function () {
			var cur = settings.value.trim();
			if (cur !== '' && cur !== '{}' && !window.confirm(examples.getAttribute('data-confirm-text'))) return;
			settings.value = item.getAttribute('data-example');
			settings.dispatchEvent(new Event('input', {bubbles: true}));
			checkJson();
			settings.focus();
		});
	});

	// unsaved changes (0.8.394): Open page, Copy AI prompt and Create from template use the saved settings – off until saved
	var dirty = false;
	function setDirty() {
		if (dirty) return;
		dirty = true;
		document.querySelectorAll('.pgNeedsSaved').forEach(function (el) {
			if (el.tagName === 'A') { el.classList.add('disabled'); el.setAttribute('aria-disabled', 'true'); el.setAttribute('tabindex', '-1'); }
			else el.disabled = true;
		});
		var hint = $('#pgSaveFirst');
		if (hint) hint.classList.remove('d-none');
	}
	if (form) { form.addEventListener('input', setDirty); form.addEventListener('change', setDirty); }
	if (form) form.addEventListener('submit', function (e) {
		if (checkJson() === false) { e.preventDefault(); settings.focus(); }
	});

	// "Copy AI prompt": the guide, this page and the tables – to the clipboard
	var prompt = $('#pgPrompt');
	if (prompt) prompt.addEventListener('click', function () {
		var csrf = document.querySelector('input[name=_csrf]');
		var body = new URLSearchParams();
		body.set('_csrf', csrf ? csrf.value : '');
		body.set('action', 'prompt');
		prompt.disabled = true;
		fetch(window.location.href, {method: 'POST', body: body, credentials: 'same-origin', headers: {Accept: 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (d) {
				var done = function () { flash(prompt.getAttribute('data-done'), 'success'); };
				if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(d.text).then(done);
				var ta = document.createElement('textarea');
				ta.value = d.text;
				ta.setAttribute('readonly', '');
				ta.className = 'position-fixed top-0 start-0 opacity-0';
				document.body.appendChild(ta);
				ta.select();
				var ok = document.execCommand('copy');
				ta.remove();
				if (!ok) throw new Error('copy');
				done();
			})
			.catch(function () { flash(prompt.getAttribute('data-fail'), 'danger'); })
			.then(function () { prompt.disabled = false; });
	});

	function flash(text, type) {
		var box = document.createElement('div');
		box.className = 'alert alert-' + type + ' alert-dismissible fade show mt-2';
		box.setAttribute('role', 'status');
		box.textContent = text;
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'btn-close';
		close.setAttribute('data-bs-dismiss', 'alert');
		close.setAttribute('aria-label', 'Close');
		box.appendChild(close);
		var bar = document.querySelector('.nuTitleBar');
		bar.parentNode.insertBefore(box, bar.nextSibling);
		setTimeout(function () { box.remove(); }, 4000);
	}
}());
