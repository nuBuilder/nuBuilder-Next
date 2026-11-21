/*
 * Shared helpers of nuBuilder Next's page scripts (0.8.1350, code review R1 / R2 / Q4) – loaded on every page right after nubs.js,
 * before nuapp.js and the page's own script. One place to review instead of a copy in every file:
 *   NuUtil.el(tag, classOrAttrs, textOrChildren)  an element: 'cls' or {class, text, html, onclick …, other attributes}; a text, a node or a list
 *   NuUtil.esc(value)                             HTML-escaped text (& < > " ')
 *   NuUtil.csrf()                                 the form token of the page
 *   NuUtil.post(url, body, opt)                   fetch POST with the token: an object goes as JSON; answers the parsed JSON
 *   NuUtil.toast(text, ok, opt)                   a short message bottom right (ok false = error); opt {box: id, action: [label, fn], ms}
 *   nuStore.get / set / remove(key)               localStorage that never throws (private windows, blocked storage); nuStore.session.* = sessionStorage
 */
(function () {
	'use strict';
	if (window.NuUtil) return;

	var ESC = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); }

	function add(e, c) {
		if (c === null || c === undefined || c === false) return;
		if (Array.isArray(c)) { c.forEach(function (x) { add(e, x); }); return; }
		e.appendChild(typeof c === 'object' && c.nodeType ? c : document.createTextNode(String(c)));
	}
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		if (typeof attrs === 'string') { if (attrs) e.className = attrs; }
		else if (attrs) Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined) return;
			// false leaves the attribute out (disabled: false) – except aria-* / data-*, where "false" is a value
			if (v === false && !/^(aria|data)-/.test(k)) return;
			if (k === 'text') e.textContent = v;
			else if (k === 'html') e.innerHTML = v;
			else if (k === 'class' || k === 'className') e.className = v;
			else if (k.slice(0, 2) === 'on' && typeof v === 'function') e.addEventListener(k.slice(2), v);
			else e.setAttribute(k, v === true ? '' : v);
		});
		if (Array.isArray(kids) || (kids && typeof kids === 'object' && kids.nodeType)) add(e, kids);
		else if (kids !== null && kids !== undefined) e.textContent = String(kids);
		return e;
	}

	function csrf() {
		var n = window.nuNext;
		if (n && n.csrf) return n.csrf;
		var m = document.querySelector('meta[name="nu-csrf"]');
		if (m) return m.getAttribute('content') || '';
		var d = document.querySelector('[data-csrf]');
		if (d) return d.getAttribute('data-csrf') || '';
		var i = document.querySelector('input[name="_csrf"]');
		return i ? i.value : '';
	}

	/**
	 * POST with the form token. body: a plain object (sent as JSON), FormData, URLSearchParams or a string. Answers the parsed JSON;
	 * an answer that is not JSON gives {ok: false, error: 'HTTP <status>'} – opt.strict rejects instead, opt.raw answers the Response.
	 * opt: {csrf, headers, method, keepalive, signal, raw, strict}
	 */
	function post(url, body, opt) {
		opt = opt || {};
		var h = {'X-CSRF-Token': opt.csrf || csrf(), 'Accept': 'application/json'};
		var json = body !== undefined && body !== null && typeof body === 'object' && !(body instanceof FormData) && !(body instanceof URLSearchParams) && !(body instanceof Blob);
		if (json) h['Content-Type'] = 'application/json';
		Object.keys(opt.headers || {}).forEach(function (k) { h[k] = opt.headers[k]; });
		var o = {method: opt.method || 'POST', credentials: 'same-origin', headers: h, body: json ? JSON.stringify(body) : body};
		if (opt.keepalive) o.keepalive = true;
		if (opt.signal) o.signal = opt.signal;
		var p = fetch(url, o);
		if (opt.raw) return p;
		return p.then(function (r) {
			return r.json().catch(function (e) {
				if (opt.strict) throw e;
				return {ok: false, error: 'HTTP ' + r.status};
			});
		});
	}

	/** A message bottom right; ok false = error (stays longer, role alert). opt: {box: id of the stack, action: [label, fn], ms}. */
	function toast(msg, ok, opt) {
		opt = opt || {};
		ok = ok !== false;
		var id = opt.box || 'nuToastStack', box = document.getElementById(id);
		if (!box) {
			box = el('div', {id: id, 'class': 'position-fixed bottom-0 end-0 p-3 nuToastStack'});
			document.body.appendChild(box);
		}
		var a = el('div', {'class': 'alert d-flex align-items-center gap-3 py-2 px-3 small shadow-sm mb-2 ' + (ok ? 'alert-success' : 'alert-danger'), role: ok ? 'status' : 'alert'},
			[el('span', null, msg)]);
		if (opt.action) a.appendChild(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-success py-0 ms-auto', text: opt.action[0],
			onclick: function () { a.remove(); opt.action[1](); }}));
		box.appendChild(a);
		setTimeout(function () { a.remove(); }, opt.ms || (opt.action ? 8000 : (ok ? 2500 : 7000)));
		return a;
	}

	window.NuUtil = {el: el, esc: esc, csrf: csrf, post: post, toast: toast};

	function store(kind) {
		var s = function () { try { return window[kind]; } catch (e) { return null; } };
		return {
			get: function (k, def) { try { var v = s().getItem(k); return v === null && def !== undefined ? def : v; } catch (e) { return def === undefined ? null : def; } },
			set: function (k, v) { try { s().setItem(k, String(v)); return true; } catch (e) { return false; } },
			remove: function (k) { try { s().removeItem(k); return true; } catch (e) { return false; } }
		};
	}
	window.nuStore = store('localStorage');
	window.nuStore.session = store('sessionStorage');
})();
