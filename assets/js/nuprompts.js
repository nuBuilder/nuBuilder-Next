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
	var locale = document.documentElement.lang || undefined;   // de-CH / de-DE / en-US … (language variant)
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

	// ------------------------------------------------------------------ "Ask AI" with a configured AI service (nuaiask.js)
	var askHost = document.getElementById('pgAsk');
	var askCtx = document.getElementById('pgAskCtx'), ctxSize = document.getElementById('pgAskCtxSize'), ctxCache = {};
	function pagePost(data) {
		var csrf = form.querySelector('input[name="_csrf"]'), body = new URLSearchParams();
		body.set('_csrf', csrf ? csrf.value : '');
		Object.keys(data).forEach(function (k) { if (Array.isArray(data[k])) data[k].forEach(function (v) { body.append(k + '[]', v); }); else body.set(k, data[k]); });
		return fetch(form.getAttribute('action') || window.location.href, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			return r;
		});
	}
	function chosenTables() {
		var tsel = document.getElementById('pge_table');
		return tsel ? Array.prototype.map.call(tsel.selectedOptions, function (o) { return o.value; }) : [];
	}
	// the context file of the chosen tables – fetched once per choice of tables
	function contextText() {
		var tables = chosenTables(), key = tables.join('|');
		if (!ctxCache[key]) ctxCache[key] = pagePost({action: 'context', fmt: 'md', full: '0', tables: tables}).then(function (r) { return r.text(); })
			.catch(function (x) { delete ctxCache[key]; throw x; });
		return ctxCache[key];
	}
	function showCtxSize() {
		if (!ctxSize) return;
		if (!askCtx || !askCtx.checked) { ctxSize.textContent = ''; return; }
		ctxSize.textContent = ctxSize.getAttribute('data-loading') || '';
		contextText().then(function (t) {
			if (!askCtx.checked) return;
			var fmt = function (v) { return Number(v).toLocaleString(); };
			ctxSize.textContent = (ctxSize.getAttribute('data-size') || '%s %s').replace('%s', fmt(t.length)).replace('%s', fmt(Math.round(t.length / 4)));
		}).catch(function (x) { ctxSize.textContent = x.message; });
	}
	if (askCtx) {
		askCtx.checked = nuStore.get('nunext-pg-ask-ctx') === '1';
		askCtx.addEventListener('change', function () { nuStore.set('nunext-pg-ask-ctx', askCtx.checked ? '1' : '0'); showCtxSize(); });
		var tselC = document.getElementById('pge_table');
		if (tselC) tselC.addEventListener('change', showCtxSize);
		showCtxSize();
	}
	// the answer: Markdown shown formatted (server: NuMarkdown, escaped first), a Copy button on every code block
	function showAnswer(text) {
		var box = document.getElementById('pgAnswerBox'), view = document.getElementById('pgAnswerView');
		document.getElementById('pgAnswer').value = text;
		box.hidden = false;
		view.textContent = text;
		pagePost({action: 'md', text: text}).then(function (r) { return r.json(); }).then(function (d) {
			if (!d || !d.ok) return;
			view.innerHTML = d.html;
			view.querySelectorAll('pre').forEach(function (pre) {
				var wrap = document.createElement('div'), btn = document.createElement('button');
				wrap.className = 'pgCodeWrap';
				btn.type = 'button'; btn.className = 'btn btn-sm btn-outline-secondary pgCodeCopy';
				btn.textContent = view.getAttribute('data-copy') || 'Copy';
				pre.parentNode.insertBefore(wrap, pre);
				wrap.appendChild(pre); wrap.appendChild(btn);
				btn.addEventListener('click', function () {
					var done = function () {
						btn.textContent = view.getAttribute('data-copied') || 'Copied';
						setTimeout(function () { btn.textContent = view.getAttribute('data-copy') || 'Copy'; }, 1600);
					};
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(pre.textContent).then(done, function () {});
					else {
						var ta = document.createElement('textarea');
						ta.value = pre.textContent; document.body.appendChild(ta); ta.select();
						try { document.execCommand('copy'); done(); } catch (x) { /* expected: no clipboard */ }
						ta.remove();
					}
				});
			});
		}).catch(function () { /* expected: the plain text stays */ });
	}
	if (window.NuAiAsk && askHost) window.NuAiAsk.mount(askHost, {
		prompt: function () {
			if (!askCtx || !askCtx.checked) return result.value;
			return contextText().then(function (t) { return t + '\n\n---\n\n' + result.value; });
		},
		answer: showAnswer
	});

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

	// ------------------------------------------------------------------ AI context file (as Form with AI): download, attach to the chat
	var ctxBox = document.getElementById('pgCtx'), ctxMsg = document.getElementById('pgCtxMsg');
	if (ctxBox) ctxBox.addEventListener('click', function (e) {
		var b = e.target.closest('[data-nu-ctx]');
		if (!b) return;
		var full = document.getElementById('pgCtxFull'), csrf = form.querySelector('input[name="_csrf"]');
		var body = new URLSearchParams();
		body.set('_csrf', csrf ? csrf.value : ''); body.set('action', 'context');
		body.set('fmt', b.getAttribute('data-nu-ctx')); body.set('full', full && full.checked ? '1' : '0');
		var tsel = document.getElementById('pge_table');
		if (tsel) Array.prototype.forEach.call(tsel.selectedOptions, function (o) { body.append('tables[]', o.value); });
		if (ctxMsg) ctxMsg.textContent = '';
		fetch(form.getAttribute('action') || window.location.href, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) {
			if (!r.ok) throw new Error('HTTP ' + r.status);
			var name = r.headers.get('X-Nu-File-Name') || 'nunext-context.md';
			return r.blob().then(function (bl) {
				var url = URL.createObjectURL(bl), a = document.createElement('a');
				a.href = url; a.download = name; a.className = 'd-none';
				document.body.appendChild(a); a.click(); a.remove();
				setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
				if (ctxMsg) { ctxMsg.textContent = ctxMsg.getAttribute('data-done') || ''; ctxMsg.className = 'small w-100 text-success'; }
			});
		}).catch(function (x) {
			if (ctxMsg) { ctxMsg.textContent = (ctxMsg.getAttribute('data-failed') || '%s').replace('%s', x.message); ctxMsg.className = 'small w-100 text-danger'; }
		});
	});
})();
