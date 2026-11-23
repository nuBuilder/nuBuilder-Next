/* global nuBs */
/*
 * nuBuilder Next form runtime + nuBuilder JavaScript API compatibility layer.
 *
 * Existing nuBuilder custom code (sfo_javascript, events, header functions) calls nu* functions and
 * relies on nuBuilder element IDs. This file provides those functions on window. Anything a form
 * defines itself (nuBeforeSave, nuCalculateForm, nuGetLookupCode …) overrides the defaults, because
 * the runtime always calls them through window.
 *
 * Internal event handling uses jQuery, because custom code fires events with $(…).change().
 */
(function () {
	'use strict';

	var N = window.nuNext || {};
	var $ = window.jQuery;
	// nuBuilder Forte's id of the loaded page (index.php: Date.now()); form code compares it with parent.nuDocumentID to know
	// whether it runs inside a popup / framed list
	if (window.nuDocumentID === undefined) window.nuDocumentID = Date.now();
	var M = N.messages || {};
	N.props = N.props || {};
	N.globals = N.globals || {};
	N.locked = N.locked || [];
	var propsDirty = false;      // properties changed in the browser since the page's scope was stored on the server
	N.edited = false;
	N.jsErrors = [];

	function g(id) { return id ? document.getElementById(id) : null; }
	function arr(x) { return x === undefined || x === null ? [] : (Array.isArray(x) ? x : [x]); }
	function esc(s) { return NuUtil.esc(s); }
	// HTML from form code (nuSetTitle, nuSetLabelText, nuMessage) without script (security assessment K2): a record value such
	// as <img src=x onerror=…> passed to these helpers ran as script in the next user's browser. Parsed in an inert <template> (nothing
	// loads or runs there), script-like elements, on… attributes and javascript: / vbscript: / non-image data: URLs are removed, and the
	// nodes are moved over as they are (no second parse). Formatting (<b>, <br>, <span class>, icons, links, inputs) stays.
	// nuNextSafeHtml(false) in a form's JavaScript turns it off for nuMessage only (nuBuilder code with onclick buttons in messages).
	var SAFE_DROP = {script: 1, iframe: 1, frame: 1, frameset: 1, object: 1, embed: 1, applet: 1, base: 1, meta: 1, link: 1, noscript: 1,
		template: 1, animate: 1, set: 1, animatemotion: 1, animatetransform: 1, handler: 1, portal: 1, foreignobject: 1};
	var SAFE_URL = /^(href|src|action|formaction|xlink:href|data|poster|background|lowsrc|dynsrc|ping|cite|codebase|srcset|to|from|values)$/;
	var safeMessages = true;
	function safeFrag(html) {
		var t = document.createElement('template'), dropped = 0;
		t.innerHTML = html === null || html === undefined ? '' : String(html);
		Array.prototype.slice.call(t.content.querySelectorAll('*')).forEach(function (el) {
			if (!t.content.contains(el)) return;                     // inside an element removed before
			if (SAFE_DROP[String(el.localName).toLowerCase()]) { el.remove(); dropped++; return; }
			Array.prototype.slice.call(el.attributes).forEach(function (at) {
				var n = at.name.toLowerCase(), v = String(at.value).replace(/[\u0000- \u007f-\u009f]/g, '').toLowerCase();
				if (n.indexOf('on') === 0 || n === 'srcdoc' || n === 'style' && /expression\(|url\(\s*['"]?\s*javascript:/.test(v)
					|| SAFE_URL.test(n) && (/^(javascript|vbscript):/.test(v) || /^data:/.test(v) && !/^data:image\/(png|jpe?g|gif|webp|bmp|avif);/.test(v))) {
					el.removeAttribute(at.name); dropped++;
				}
			});
		});
		if (dropped && window.console) console.warn('nuBuilder Next removed ' + dropped + ' script part(s) from HTML given to a message / title helper.');
		return t.content;
	}
	api('nuNextSafeHtml', function (on) { safeMessages = on !== false; });
	api('nuSafeHtml', function (html) { var d = document.createElement('div'); d.appendChild(safeFrag(html)); return d.innerHTML; });
	function globalEval(code) { if (code && String(code).trim() !== '') (0, eval)(String(code)); }   // eslint-disable-line no-eval
	function runSnippet(code, event, thisArg) {
		if (!code || !String(code).trim()) return;
		try { return new Function('event', code).call(thisArg || window, event || {}); }   // eslint-disable-line no-new-func
		catch (e) { reportError(e.message, 'event code'); console.error(e); }
	}
	function api(name, fn) { window[name] = fn; }
	/** Label text without the validation markers (* / key and their screen reader words). */
	function labelText(id) { return $('#label_' + id).first().clone().find('.nuMarks, .nuRequired').remove().end().text().trim(); }

