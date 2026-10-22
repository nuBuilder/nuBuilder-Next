/*
 * Prompt generator editor: Tom Select for languages / scopes / topics / tables, live preview of the complete prompt
 * (built on the server, POST action=build), Copy, topics suggested from words in the prompt (no AI), delete confirmation.
 */
(function () {
	'use strict';
	var form = document.getElementById('pgForm');
	if (!form) return;
	var catalog = JSON.parse(form.getAttribute('data-catalog') || '{}');
	var result = document.getElementById('pgResult'), stats = document.getElementById('pgStats'), save = document.getElementById('pgSave');
	var selects = {};

	// ------------------------------------------------------------------ multiple selects
	form.querySelectorAll('select.pgMulti').forEach(function (sel) {
		if (!window.TomSelect) return;
		var max = parseInt(sel.getAttribute('data-max') || '0', 10);
		selects[sel.id] = new TomSelect(sel, {
			plugins: ['remove_button'], maxItems: max || null, hideSelected: true, closeAfterSelect: false,
			maxOptions: 500, create: false, onChange: changed
		});
	});

	// ------------------------------------------------------------------ live preview
	var timer = null, seq = 0;
	var locale = document.documentElement.lang || undefined;   // de-CH / de-DE / en-US … (language variant, 0.8.315)
	function fmt(n) { return Number(n).toLocaleString(locale); }
	function showStats(chars) {
		if (stats) stats.textContent = (form.getAttribute('data-stats') || '%s').replace('%s', fmt(chars));
	}
	function rebuild() {
		var data = new FormData(form), my = ++seq;
		data.set('action', 'build');
		fetch(form.getAttribute('action'), {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (j) {
				if (!j || my !== seq) return;                       // an older answer arriving late
				result.value = j.prompt;
				showStats(j.chars);
			}, function () {});
	}
	function changed() {
		if (save) save.classList.add('nuSaveEdited');
		clearTimeout(timer);
		timer = setTimeout(rebuild, 350);
	}
	form.querySelectorAll('.pgInput, #pge_code, #pge_description, #pge_template').forEach(function (el) {
		el.addEventListener('input', changed);
		el.addEventListener('change', changed);
	});
	showStats(result.value.length);

	// ------------------------------------------------------------------ copy
	var copyBtn = document.getElementById('pgCopy');
	copyBtn.addEventListener('click', function () {
		var label = copyBtn.querySelector('span'), orig = label.textContent;
		var done = function (ok) {
			if (!ok) return;
			label.textContent = form.getAttribute('data-copied') || orig;
			copyBtn.classList.replace('btn-primary', 'btn-success');
			setTimeout(function () { label.textContent = orig; copyBtn.classList.replace('btn-success', 'btn-primary'); }, 1800);
		};
		var fallback = function () {
			result.focus(); result.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (x) {}
			result.setSelectionRange(0, 0);
			done(ok);
		};
		if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(result.value).then(function () { done(true); }, fallback);
		else fallback();
	});

	// ------------------------------------------------------------------ topics from the prompt (local keyword matching)
	var suggest = document.getElementById('pgSuggest'), info = document.getElementById('pgSuggestInfo');
	function escRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
	// the wand fills topics, and adds matching languages and scopes too ("before save" → PHP Before Save)
	if (suggest) suggest.addEventListener('click', function () {
		var text = ' ' + String(document.getElementById('pge_prompt').value || '').toLowerCase() + ' ';
		var added = 0, lists = catalog.suggest || {};
		Object.keys(lists).forEach(function (field) {
			var ts = selects[field];
			if (!ts) return;
			Object.keys(lists[field]).forEach(function (code) {
				var hit = lists[field][code].some(function (k) {
					return new RegExp('(^|[^a-z0-9äöüß_])' + escRe(k.toLowerCase())).test(text);   // word start: "report" also finds "reports"
				});
				if (hit && ts.items.indexOf(code) === -1 && (!ts.settings.maxItems || ts.items.length < ts.settings.maxItems)) { ts.addItem(code, true); added++; }
			});
		});
		if (added) changed();
		info.textContent = added ? (form.getAttribute('data-suggest-added') || '%d').replace('%d', added) : form.getAttribute('data-suggest-none');
	});

	// ------------------------------------------------------------------ delete confirmation
	form.querySelectorAll('[data-confirm-submit]').forEach(function (b) {
		b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm-submit'))) e.preventDefault(); });
	});
})();
