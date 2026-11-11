/* "Ask AI" (0.8.1099, core/nuaiprovider.php, route ?p=aiask): sends the prompt of an AI dialogue to a configured AI service and puts
   the answer where a pasted answer goes. The dialogue's own check then runs as for a pasted answer – nothing is applied here.

   NuAiAsk.mount(host, opt) – host: an element with data-nu-ai (NuAiProvider::host) or opt.data = NuAiProvider::client();
   opt.prompt()        → the prompt text, or a Promise of it ('' / rejected = nothing is sent – the dialogue shows why)
   opt.answer(text)    puts the answer into the dialogue
   opt.check()         optional → Promise of the check's errors ([] = fine). With errors, ONE correction round follows:
                       the errors go back to the AI and the corrected answer is checked again.
   Returns {el, busy()} or null when there is nothing to mount. */
(function () {
	'use strict';
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') e.textContent = attrs[k]; else e.setAttribute(k, attrs[k]); });
		(kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	var n = 0;
	function mount(host, opt) {
		if (!host || host.getAttribute('data-nu-ai-on') === '1') return null;
		var D = opt.data || null;
		if (!D) { try { D = JSON.parse(host.getAttribute('data-nu-ai') || 'null'); } catch (e) { D = null; } }
		if (!D || !D.configs || !D.configs.length) return null;
		host.setAttribute('data-nu-ai-on', '1');
		var M = D.msg || {}, uid = 'nuAiAsk' + (++n), ctl = null, busy = false;
		var sel = el('select', {'class': 'form-select form-select-sm nuAiAskSel', 'aria-label': M.aip_config, id: uid + 'Sel'});
		D.configs.forEach(function (c) { sel.appendChild(el('option', {value: c.id, text: c.name + (c.model ? ' · ' + c.model : '')})); });
		var saved = null;
		try { saved = window.localStorage.getItem('nunext-ai-config'); } catch (e) { saved = null; }
		if (saved && D.configs.some(function (c) { return c.id === saved; })) sel.value = saved;
		var btn = el('button', {type: 'button', 'class': 'btn btn-sm btn-primary nuAiAskBtn', title: M.aip_ask_tip}, [el('i', {'class': 'bi bi-stars me-1', 'aria-hidden': 'true'}), M.aip_ask]);
		var stop = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary nuAiAskStop', hidden: 'hidden'}, [M.aip_cancel]);
		var msg = el('span', {'class': 'small nuAiAskMsg', role: 'status', 'aria-live': 'polite'});
		var pw = el('input', {type: 'password', 'class': 'form-control form-control-sm nuAiAskPw', placeholder: M.aip_password, 'aria-label': M.aip_password, autocomplete: 'off'});
		var pwBtn = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary nuAiAskUnlock'}, [el('i', {'class': 'bi bi-unlock me-1', 'aria-hidden': 'true'}), M.aip_unlock]);
		var pwBox = el('div', {'class': 'input-group input-group-sm nuAiAskPwBox', hidden: 'hidden'}, [pw, pwBtn]);
		var row = el('div', {'class': 'd-flex flex-wrap gap-2 align-items-center'}, [sel, btn, stop, pwBox, msg]);
		var note = el('div', {'class': 'form-text nuAiAskNote', text: M.aip_sent_note});
		host.appendChild(row);
		host.appendChild(note);
		if (D.configs.length === 1) sel.classList.add('nuAiAskOne');

		function cfg() { return D.configs.filter(function (c) { return c.id === sel.value; })[0] || D.configs[0]; }
		function say(text, cls) { msg.textContent = text || ''; msg.className = 'small nuAiAskMsg' + (cls ? ' ' + cls : ''); }
		function sync() {
			var locked = !!cfg().locked;
			pwBox.hidden = !locked || busy;
			btn.hidden = locked;
			try { window.localStorage.setItem('nunext-ai-config', sel.value); } catch (e) { /* private window */ }
		}
		sel.addEventListener('change', function () { say(''); sync(); });
		function post(data, signal) {
			var b = new URLSearchParams();
			b.set('_csrf', D.csrf || ''); b.set('feature', D.feature); b.set('provider', sel.value);
			Object.keys(data).forEach(function (k) { if (Array.isArray(data[k])) data[k].forEach(function (v) { b.append(k + '[]', v); }); else b.set(k, data[k]); });
			return fetch(D.url, {method: 'POST', body: b, credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: signal}).then(function (r) { return r.json(); });
		}
		function unlock() {
			if (!pw.value) { pw.focus(); return; }
			pwBtn.disabled = true;
			post({action: 'unlock', password: pw.value}).then(function (d) {
				if (!d.ok) { say(d.error || M.aip_failed, 'text-danger'); pw.select(); return; }
				cfg().locked = false; pw.value = ''; say(''); sync(); btn.focus();
			}).catch(function () { say(M.aip_failed, 'text-danger'); }).then(function () { pwBtn.disabled = false; });
		}
		pwBtn.addEventListener('click', unlock);
		pw.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); unlock(); } });

		function setBusy(on) {
			busy = on;
			btn.disabled = on; sel.disabled = on; stop.hidden = !on;
			host.classList.toggle('nuAiAskBusy', on);
			if (typeof opt.busy === 'function') opt.busy(on);
		}
		function ask(data) {
			ctl = typeof AbortController === 'function' ? new AbortController() : null;
			return post(Object.assign({action: 'ask'}, data), ctl ? ctl.signal : undefined);
		}
		function run() {
			if (busy) return;
			say('');
			var prompt = '';
			Promise.resolve().then(function () { return opt.prompt(); }).then(function (p) {
				prompt = String(p || '');
				if (!prompt.trim()) return null;
				setBusy(true);
				say(M.aip_waiting, 'text-body-secondary nuAiAskWait');
				return ask({prompt: prompt});
			}).then(function (d) {
				if (!d) return null;
				if (!d.ok) { say(d.error || M.aip_failed, 'text-danger'); return null; }
				opt.answer(d.text);
				say(M.aip_done + (d.note ? ' ' + d.note : ''), d.note ? 'text-warning' : 'text-success');
				if (typeof opt.check !== 'function') return null;
				return Promise.resolve(opt.check()).then(function (errs) {
					if (!errs || !errs.length) return null;
					say(M.aip_fixing, 'text-body-secondary nuAiAskWait');             // one correction round
					return ask({prompt: prompt, prev: d.text, errors: errs}).then(function (d2) {
						if (!d2.ok) { say(d2.error || M.aip_failed, 'text-danger'); return null; }
						opt.answer(d2.text);
						return Promise.resolve(opt.check()).then(function (e2) {
							if (e2 && e2.length) say(M.aip_still_errors, 'text-warning'); else say(M.aip_fixed, 'text-success');
						});
					});
				});
			}).catch(function (e) {
				say(e && e.name === 'AbortError' ? M.aip_cancelled : M.aip_failed, e && e.name === 'AbortError' ? 'text-body-secondary' : 'text-danger');
			}).then(function () { setBusy(false); sync(); });
		}
		btn.addEventListener('click', run);
		stop.addEventListener('click', function () { if (ctl) ctl.abort(); });
		sync();
		return {el: host, busy: function () { return busy; }, run: run};
	}
	window.NuAiAsk = {mount: mount};
})();
