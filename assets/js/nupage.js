// nuBuilder Next page SDK (form type "page", 0.8.387): loaded by a page with <?= $nu->sdk() ?>.
// NuPage.csrf · NuPage.fetch(url, options) · NuPage.resize() · NuPage.close() · NuPage.refreshParent() · NuPage.openForm(code, record, {target})
// NuPage.toast(text, type) · NuPage.setTitle(text) · NuPage.inFrame · NuPage.version (= apiVersion, the page API version, 0.8.388). Messages to the nuBuilder Next window around the page:
// postMessage({nuPage: 1, type, …}, location.origin) – handled by assets/js/nupagehost.js. Outside an iframe everything degrades quietly.
(function () {
	'use strict';
	var me = document.currentScript;
	var csrf = me ? me.getAttribute('data-csrf') || '' : '';
	var home = me ? me.getAttribute('data-home') || 'index.php?p=home' : 'index.php?p=home';
	var inFrame = false;
	try {
		inFrame = window.parent !== window && window.parent.location.origin === window.location.origin
			&& !!window.frameElement && window.frameElement.classList.contains('nuPageFrame');
	} catch (e) { inFrame = false; }

	function post(type, data) {
		if (!inFrame) return false;
		var m = {nuPage: 1, type: type};
		Object.keys(data || {}).forEach(function (k) { m[k] = data[k]; });
		window.parent.postMessage(m, window.location.origin);
		return true;
	}

	function formUrl(code, record) {
		return 'index.php?p=form&f=' + encodeURIComponent(code) + (record !== undefined && record !== null && record !== '' ? '&r=' + encodeURIComponent(record) : '');
	}

	var lastHeight = 0;
	function resize() {
		var h = Math.ceil(Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0));
		if (h === lastHeight) return;
		lastHeight = h;
		post('resize', {height: h});
	}

	var NuPage = {
		csrf: csrf,
		inFrame: inFrame,
		apiVersion: 1,
		version: 1,          // same rules as $nu->api(): methods are only added within a version (NuPage::JS_API lists them)

		/** fetch() with the CSRF token and JSON headers. 401 = signed out: the page (or the window around it) reloads to the login. */
		fetch: function (url, options) {
			options = options || {};
			var headers = new Headers(options.headers || {});
			if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', csrf);
			if (!headers.has('Accept')) headers.set('Accept', 'application/json');
			headers.set('X-Requested-With', 'XMLHttpRequest');
			var opts = {};
			Object.keys(options).forEach(function (k) { opts[k] = options[k]; });
			opts.headers = headers;
			opts.credentials = opts.credentials || 'same-origin';
			return window.fetch(url, opts).then(function (r) {
				if (r.status === 401) {
					if (!post('login')) window.location.reload();
				} else if (r.status === 400) {
					r.clone().json().then(function (d) {
						if (d && d.error === 'csrf') if (d.message) NuPage.toast(d.message, 'warning');
					}).catch(function () {});
				}
				return r;
			});
		},

		resize: resize,

		close: function () {
			if (post('close')) return;
			if (window.opener) { window.close(); return; }
			if (window.history.length > 1) window.history.back(); else window.location.href = home;
		},

		refreshParent: function () {
			if (post('refresh')) return;
			try { if (window.opener && window.opener.location.origin === window.location.origin) window.opener.location.reload(); } catch (e) { /* other origin */ }
		},

		openForm: function (code, record, opt) {
			var target = (opt && opt.target) || 'self';
			if (post('open', {form: String(code), record: record === undefined || record === null ? '' : String(record), target: target})) return;
			if (target === 'tab') window.open(formUrl(code, record), '_blank', 'noopener'); else window.location.href = formUrl(code, record);
		},

		toast: function (text, type) {
			if (!post('toast', {text: String(text), level: type || 'info'})) {
				if (window.console) window.console.log('[' + (type || 'info') + '] ' + text);
			}
		},

		setTitle: function (text) {
			if (!post('title', {text: String(text)})) document.title = String(text);
		}
	};
	// deprecated names (NuPage::JS_DEPRECATED): wrap them here with deprecated('name', 'replacement') – one console warning per page view
	var warned = {};
	function deprecated(name, use) {   // eslint-disable-line no-unused-vars
		var fn = NuPage[name];
		NuPage[name] = function () {
			if (!warned[name] && window.console) { warned[name] = 1; window.console.warn('NuPage.' + name + ' is deprecated – use ' + use); }
			return fn.apply(NuPage, arguments);
		};
	}
	window.NuPage = NuPage;

	if (inFrame) {
		if (window.ResizeObserver) {
			var ro = new ResizeObserver(function () { resize(); });
			var start = function () { ro.observe(document.documentElement); if (document.body) ro.observe(document.body); resize(); };
			if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
		}
		window.addEventListener('load', resize);
	}
}());
