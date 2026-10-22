/*
 * More nuBuilder 4.9 JavaScript functions (nucommon.js, nuform.js, nuajax.js) for existing form code.
 * Loaded after nuruntime.js; works on nuBuilder Next's markup (subform rows .nuSubformRow with data-nu-prefix,
 * tabs .nuTab[data-nu-tab][data-nu-tab-id], browse cells td.nuCell[data-nu-column]).
 * Open questions and functions that are not ported: docs/porting-review.md.
 */
(function () {
	'use strict';

	var N = window.nuNext || {};
	var $ = window.jQuery;
	var M = N.messages || {};
	function g(id) { return id ? document.getElementById(id) : null; }
	function api(name, fn) { window[name] = fn; }
	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
	function post(url, data) {                       // a real form POST (logout etc.) with the CSRF token
		var f = document.createElement('form');
		f.method = 'post'; f.action = url;
		Object.keys(data).forEach(function (k) { var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = data[k]; f.appendChild(i); });
		document.body.appendChild(f); f.submit();
	}

	// =================================================================== strings, arrays, small helpers
	api('nuEscapeHTML', function (string, extra) {
		if (typeof string !== 'string') return '';
		var r = $.extend({'<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#039;', '`': '&#x60;', '\\': '&#92;'}, extra || {});
		var re = new RegExp('[' + Object.keys(r).map(function (c) { return '\\' + c; }).join('') + ']', 'g');
		return string.replace(re, function (c) { return r[c]; });
	});
	api('nuAddSlashes', function (s) { return String(s).replace(/[\\"']/g, '\\$&').replace(/\u0000/g, '\\0'); });
	api('nuAddThousandSpaces', function (s, d) { return String(s).replace(/\B(?=(\d{3})+(?!\d))/g, d); });
	api('nuPad', function (id, length, pad) { return String(id).padStart(length, pad === undefined ? '0' : pad); });
	api('nuSpaces', function (n) { return '&nbsp;'.repeat(n); });
	api('nuRemovePX', function (s) { return Number(String(s).split('px')[0]); });
	api('nuArrayColumn', function (a, n) { return a.map(function (x) { return x[n]; }); });
	api('nuArrayIsUnique', function (a) { return a.length === new Set(a).size; });
	api('nuShuffleArray', function (a) {
		for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), t = a[i]; a[i] = a[j]; a[j] = t; }
	});
	api('nuCharacterArray', function (symbols, numbers, lower, upper) {
		var s = [];
		if (symbols !== false) s.push.apply(s, '!@#%&*()-_+={}[]|:;<>.?'.split(''));
		if (numbers !== false) s.push.apply(s, '0123456789'.split(''));
		if (lower !== false) s.push.apply(s, 'abcdefghijklmnopqrstuvwxyz'.split(''));
		if (upper !== false) s.push.apply(s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split(''));
		return s;
	});
	// nuBuilder shuffles the characters and takes the first ones (no repeats); here: secure random, repeats allowed when longer than the set
	api('nuRandomPassword', function (chars, size) {
		chars = chars && chars.length ? chars : nuCharacterArray();
		var out = '', buf = new Uint32Array(size || 0);
		(window.crypto || window.msCrypto).getRandomValues(buf);
		for (var i = 0; i < buf.length; i++) out += chars[buf[i] % chars.length];
		return out;
	});
	api('nuMonthArray', function (options, locale) {
		var a = [];
		for (var i = 0; i < 12; i++) a.push(new Date(Date.UTC(2000, i, 1)).toLocaleString(locale || 'en-US', options || {month: 'long'}));
		return a;
	});
	api('nuDateIsValid', function (d) { return Object.prototype.toString.call(d) === '[object Date]' && !isNaN(d); });
	api('nuObjectIdIsValid', function (id) { return typeof id === 'string' && /^[^\d\s][^\s]*$/u.test(id); });
	api('nuDefine', function (v, d) { return v === undefined ? (d === undefined ? '' : d) : v; });
	api('nuDelay', function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); });
	api('nuWhen', function (ts) { if (!ts) return; var s = Math.ceil(Date.now() / 1000 - ts); return s + ' ' + (s === 1 ? 'second' : 'seconds') + ' ago'; });
	api('nuCountDefinedArguments', function () { return Array.prototype.filter.call(arguments, function (a) { return a !== undefined; }).length; });
	api('nuEncode', function (s) { return window.btoa(unescape(encodeURIComponent(s))); });
	api('nuDecode', function (s) { return decodeURIComponent(escape(window.atob(s))); });
	api('nuComposeURL', function (url, text, target, protocol, title) {
		if (!url || typeof url !== 'string' || url.trim() === '') return text;
		url = url.trim(); protocol = (protocol || '').trim(); title = (title || '').trim();
		if (protocol === 'mailto:' || protocol === 'tel:') return '<a href="' + esc(protocol + url) + '" title="' + esc(title) + '">' + text + '</a>';
		return '<a href="' + esc(protocol + url) + '" target="' + esc(target || '_blank') + '" rel="noopener" title="' + esc(title) + '">' + text + '</a>';
	});
	api('nuGetURLParameterValue', function (name) { return new URLSearchParams(window.location.search).get(name); });
	api('nuIsMacintosh', function () {
		if (navigator.userAgentData && navigator.userAgentData.platform) return /mac/i.test(navigator.userAgentData.platform);
		return /macintosh|mac os x/i.test(navigator.userAgent);
	});
	api('nuIsCtrlOrCmdPressed', function (e) { return e ? (nuIsMacintosh() ? e.metaKey : e.ctrlKey) : false; });
	api('nuCtrlCmdShiftName', function (k) { return k === '' ? '' : (nuIsMacintosh() ? 'Cmd' : 'Ctrl') + '+Shift+' + k; });
	if (!String.prototype.nuHasHTMLTag) {
		String.prototype.nuHasHTMLTag = function (tag) {
			var e = function (n) { return n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };
			var m = this.match(new RegExp('(' + (Array.isArray(tag) ? tag.map(e).join('|') : e(tag)) + ')\\b[^>]*>', 'i'));
			return m ? m[1] : false;
		};
	}

	// =================================================================== storage and window properties
	api('nuGetStorageItem', function (key, type) {
		try {
			var st = type === 'local' ? window.localStorage : window.sessionStorage;
			var s = st.getItem(key);
			if (!s) return null;
			var item = JSON.parse(s);
			if (item.expiry !== null && item.expiry !== undefined && Date.now() > item.expiry) { st.removeItem(key); return null; }
			return item.value;
		} catch (e) { return null; }
	});
	api('nuSetStorageItem', function (key, value, type, ttl) {
		try {
			(type === 'local' ? window.localStorage : window.sessionStorage).setItem(key, JSON.stringify({value: value, expiry: ttl === undefined ? null : Date.now() + ttl * 1000}));
		} catch (e) { /* storage blocked */ }
	});
	api('nuGetWindowProperty', function (key, prop) {
		if (!window[key]) return undefined;
		return prop === undefined ? window[key] : window[key][prop];
	});
	api('nuSetWindowProperty', function (key, prop, value) {
		if (!window[key]) window[key] = {};
		if (value !== undefined) window[key][prop] = value;
	});
	api('nuDestroyWindowProperty', function (key, prop) {
		var o = window[key];
		if (!o) return;
		var kill = function (p) { if (o[p] && typeof o[p].destroy === 'function') o[p].destroy(); delete o[p]; };
		if (prop !== undefined) { if (Object.prototype.hasOwnProperty.call(o, prop)) kill(prop); }
		else Object.keys(o).forEach(kill);
	});
	api('nuCallWindowFunction', function (fnName) {
		var args = Array.prototype.slice.call(arguments, 1);
		var obj = args.length && typeof args[0] === 'object' && args[0] !== null && !(args[0] instanceof Element) ? args.shift() : window;
		var fn = obj && obj[fnName];
		return typeof fn === 'function' ? fn.apply(obj, args) : undefined;
	});

	// =================================================================== DOM and objects
	api('nujQueryObj', function (id) {
		if (typeof id === 'string') return $('#' + id);
		if (id instanceof HTMLElement) return $(id);
		if (id instanceof $) return id;
		return $([]);
	});
	api('nuElement', function (id) {
		if (typeof id === 'string') return /^[#.[]/.test(id) ? Array.from(document.querySelectorAll(id)) : (g(id) ? [g(id)] : []);
		if (id instanceof HTMLElement) return [id];
		if (id instanceof NodeList || Array.isArray(id)) return Array.from(id);
		return [];
	});
	api('nuSetAttributes', function (el, attrs) { Object.keys(attrs || {}).forEach(function (k) { el.setAttribute(k, attrs[k]); }); });
	api('nuAddRemoveClasses', function ($id, style) {
		$id = $id instanceof $ ? $id : $('#' + $id);
		String(style || '').split(/\s+/).filter(Boolean).forEach(function (c) { if (c.charAt(0) === '-') $id.removeClass(c.substring(1)); else $id.addClass(c); });
	});
	api('nuObjectClassList', function (id) { var c = $('#' + id).attr('class'); return c ? c.trim().split(/\s+/).join(' ') : ''; });
	api('nuAddCSSStyle', function (css, id) {
		id = id || 'nucssstyle';
		$('#' + id).remove();
		css = String(css || '').replace(/<([^>]+)>/ig, '').trim();
		if (css === '') return;
		var s = document.createElement('style');
		s.id = id; s.appendChild(document.createTextNode(css));
		document.head.appendChild(s);
	});
	api('nuCreateAppendHTML', function (html) {
		var df = document.createDocumentFragment(), t = document.createElement('div');
		t.innerHTML = html;
		while (t.firstChild) df.appendChild(t.firstChild);
		return df;
	});
	api('nuAttachHTML', function (id, html, appendToEnd) {
		var t = id instanceof $ ? id : $('#' + id);
		if (!t.length) { console.warn('Target element not found.'); return; }
		if (appendToEnd) t.append('&nbsp;' + html); else t.prepend(html + '&nbsp;');
	});
	api('nuButtonIcon', function (id) { $(typeof id === 'string' && id.charAt(0) !== '#' ? '#' + id : id).css({'text-align': 'left', 'padding-left': '35px', 'background-size': '30px', 'background-repeat': 'no-repeat'}); });
	api('nuAddInputIcon', function (id, icon) {
		if (!icon || nuUserA11Y()) return;
		var add = function (s, after) {
			s = s.trim();
			if (s === '') return;
			var el = /^(fa|bi)/.test(s) ? $('<i>').addClass(/^bi(-|\s)/.test(s) ? 'bi ' + s.replace(/^bi\s+/, '') : s).addClass('nuInputIcon') : $('<span class="nuInputIcon">').html(s);
			if (after) $('#' + id).after(el); else $('#' + id).before(el);
			g(id) && g(id).setAttribute('nu-data-icon', s);
		};
		var p = String(icon).split('|');
		add(p[0], false);
		if (p.length > 1) add(p[1], true);
	});
	api('nuCursor', function (c) {
		document.documentElement.style.cursor = c;
		try { if (window.parent !== window) window.parent.document.documentElement.style.cursor = c; } catch (e) { /* cross-origin */ }
	});
	api('nuToggleStyleAttribute', function (id, attrs) {
		var el = g(id);
		if (!el) { console.error('Element with id "' + id + '" not found.'); return; }
		var modified = attrs.some(function (a) { return el.hasAttribute(a.dataAttr); });
		attrs.forEach(function (a) {
			if (modified) {
				var v = el.getAttribute(a.dataAttr);
				if (v !== null) { el.style[a.styleProp] = v; el.removeAttribute(a.dataAttr); }
			} else {
				el.setAttribute(a.dataAttr, el.style[a.styleProp] || '');
				if (a.value !== null && a.value !== undefined) el.style[a.styleProp] = typeof a.value === 'number' ? a.value + 'px' : a.value;
			}
		});
	});
	api('nuToggleSize', function (id, h, w) {
		nuToggleStyleAttribute(id, [{styleProp: 'height', dataAttr: 'data-nu-org-height', value: h === undefined ? null : h}, {styleProp: 'width', dataAttr: 'data-nu-org-width', value: w === undefined ? null : w}]);
	});
	api('nuTogglePosition', function (id, top, left) {
		nuToggleStyleAttribute(id, [{styleProp: 'top', dataAttr: 'data-nu-org-top', value: top === undefined ? null : top}, {styleProp: 'left', dataAttr: 'data-nu-org-left', value: left === undefined ? null : left}]);
	});
	api('nuGetSelectedText', function (input) {
		var el = typeof input === 'string' ? g(input) : input;
		return el && typeof el.selectionStart === 'number' ? el.value.substring(el.selectionStart, el.selectionEnd) : '';
	});
	api('nuInsertAtCaret', function (id, s) {
		if (window.nuInsertTextAtCaret) return nuInsertTextAtCaret(id, s);
		var el = g(id); if (!el) return;
		var p = el.selectionStart || 0;
		el.value = el.value.substring(0, p) + s + el.value.substring(p);
		el.focus(); el.selectionStart = el.selectionEnd = p + s.length;
	});
	api('nuTriggerEvent', function (el, ev) {
		ev = ev || 'change';
		if (el instanceof $) el.trigger(ev); else if (typeof el === 'string') $('#' + el).trigger(ev); else el.dispatchEvent(new Event(ev, {bubbles: true}));
	});
	api('nuAddEventListenerOnce', function (target, type, fn, options, cls) {
		if (!target.classList.contains(cls)) { target.addEventListener(type, fn, options); target.classList.add(cls); }
	});
	api('nuCopyToClipboard', function (s) {
		if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(String(s)).then(function () { return true; }, function () { return false; });
		var t = document.createElement('textarea'); t.value = String(s); document.body.appendChild(t); t.select();
		var ok = false; try { ok = document.execCommand('copy'); } catch (e) { /* ignore */ }
		t.remove();
		return Promise.resolve(ok);
	});
	api('nuInputMaxLength', function (id, max, labelId) {
		var $i = $('#' + id);
		if (max === undefined) return parseInt($i.attr('maxlength'), 10) || null;
		$i.attr('maxlength', max);
		if (labelId) {
			var upd = function () { $('#' + labelId).text((max - ($i.val() || '').length) + '/' + max); };
			upd();
			$i.off('input.nuMaxLen').on('input.nuMaxLen', upd);
		}
	});
	api('nuGetObjectId', function (id) {           // object id (zzzzsys_object_id) ↔ field id
		var objs = N.objects || {};
		for (var k in objs) if (Object.prototype.hasOwnProperty.call(objs, k) && objs[k].object_id === id) return k;
		return objs[id] ? objs[id].object_id : null;
	});
	api('nuEnableDisableAllObjects', function (enable, excludeTypes, excludeIds) {
		excludeTypes = excludeTypes || []; excludeIds = excludeIds || [];
		Object.keys(N.objects || {}).forEach(function (id) {
			var t = N.objects[id].type;
			if (excludeTypes.indexOf(t) < 0 && excludeIds.indexOf(id) < 0 && t !== 'contentbox' && t !== 'html') nuEnable(id, enable);
		});
	});
	// nuBuilder access 0–4: 1 readonly, 2 hidden, 3 hidden for users (globeadmin sees it), 4 hidden for users + readonly
	api('nuSetAccess', function (id, r) {
		r = Number(r);
		if (r === 2 || ((r === 3 || r === 4) && !nuGlobalAccess())) nuHide(id);
		if (r === 1 || r === 4) nuDisable(id);
		$('#' + id).attr('data-nu-access', r);
	});
	api('nuPreventButtonDblClick', function () {
		$('.nuActionButton, .nuButton, .nuRunButton').not('.nuAllowDblClick').off('click.nuDbl').on('click.nuDbl', function () {
			var b = $(this);
			if (b.hasClass('nuReadonly')) return;
			b.addClass('nuPreventDblClick').prop('disabled', true);
			setTimeout(function () { b.prop('disabled', false).removeClass('nuPreventDblClick'); }, 1300);
		});
	});
	api('nuIsDoubleClick', function (event, el) {
		var now = event.timeStamp, last = parseInt(el.getAttribute('nu-last-clicked-time'), 10) || 0;
		if (last && now - last < 1000) return true;
		el.setAttribute('nu-last-clicked-time', String(now));
		return false;
	});
	api('nuSetAdvancedToolTip', function (selector, a2, a3) {
		var o = typeof a2 === 'string' ? $.extend({}, a3 || {}, {content: a2}) : (a2 || {});
		var els = nuElement(selector);
		if (!els.length && typeof selector === 'string') els = Array.from(document.querySelectorAll('#' + selector));
		var css = typeof selector === 'string' && !/^[#.[]/.test(selector) ? '#' + selector : selector;
		if (o.content) els.forEach(function (el) { el.setAttribute('nu-tooltip-icon', o.content); });
		if (window.nuAttachToolTip) nuAttachToolTip($.extend({createIcon: false}, o, {selector: css}));
	});

	// =================================================================== environment
	api('nuDatabaseType', function () { return N.db_type; });
	api('nuMSSQL', function () { return N.db_type === 'sqlsrv'; });
	api('nuDatabaseName', function () { return N.db_name || ''; });          // globeadmin only
	api('nuDebugMode', function () { return !!(window.nuUXOptions || {}).nuDebugMode; });
	api('nuDebugOut', function (obj, i) { if (nuDebugMode() && obj.length === 0) { console.warn(nuTranslate('Object does not exist:'), i); return true; } return false; });
	api('nuUserA11Y', function () { return String((N.user || {}).accessibility || '') === '1'; });
	api('nuIsPopup', function () { try { return !!(window.frameElement && window.frameElement.closest('#nuPopupModal')); } catch (e) { return false; } });
	api('nuSetBrowserTabTitle', function (prefix) {
		var t = N.title || N.form.description || '';
		document.title = (prefix ? prefix + ' - ' : '') + $('<div>').html(t).text();
	});
	api('nuConsoleErrorsToMessage', function (cancel) {
		if (cancel) { window.onerror = function () { return true; }; return; }
		if (window.onerror) return;
		window.onerror = function (msg) {
			if (msg === 'ResizeObserver loop limit exceeded') return;
			var dev = nuTranslate('Please check the browser developer console for details.');
			nuMessage(nuTranslate('JavaScript Error'), String(msg).toLowerCase().indexOf('script error') > -1 ? dev : [msg, '', dev]);
			return false;
		};
	});
	api('nuEnableBrowserBackButton', function () {
		if (window.history.length > 1) window.history.pushState({page: 1}, '', ''); else window.history.replaceState({page: 1}, '', '');
		window.onpopstate = function (e) { if (e) nuOpenPreviousBreadcrumb(); };
	});
	api('nuFormsUnsaved', function () {
		var c = 0;
		$('iframe').each(function () { try { if (nuCallWindowFunction('nuIsSaved', this.contentWindow) === false) c++; } catch (e) { /* cross-origin */ } });
		if (!nuIsSaved()) c++;
		return c;
	});
	api('nuLogout', function () { try { sessionStorage.removeItem('nukeepalive'); } catch (e) { /* ignore */ } post(N.base + '?p=logout', {_csrf: N.csrf}); });
	api('nuAskLogout', function () {
		if (nuFormsUnsaved() > 0 && !window.confirm(nuTranslate('There are unsaved changes. Do you really want to leave the page?'))) return;
		nuLogout();
	});
	api('nuRefreshIframe', function (frameId) {
		var f = g(frameId);
		if (!f || !f.contentWindow) return false;
		try { f.contentWindow.location.reload(); return true; } catch (e) { return false; }
	});

	// =================================================================== procedures
	// nuBuilder: the value is passed JSON + base64 encoded as a form property; PHP reads it with nuDecode('#name#')
	api('nuRunPHPWithParams', function (code, paramName, paramValue, iFrame, runBeforeSave) {
		nuSetProperty(paramName, nuEncode(JSON.stringify(paramValue)));
		nuRunPHP(code, iFrame, runBeforeSave);
	});
	// nuBuilder: opens a procedure in its editor (zzzzsys_php id)
	api('nuGetPHP', function (formId, recordId) {
		window.open(N.base + '?p=procedures&id=' + encodeURIComponent(recordId === undefined ? formId : recordId), '_blank', 'noopener');
	});

	// =================================================================== prompt (nuPrompt → nuOnPromptClose(value, ok))
	api('nuOnPromptClose', window.nuOnPromptClose || function () {});
	api('nuPrompt', function (text, caption, defaultValue, format, fctn) {
		var cb = typeof fctn === 'function' ? fctn : (typeof fctn === 'string' && typeof window[fctn] === 'function' ? window[fctn] : function (v, ok) { window.nuOnPromptClose(v, ok); });
		var m = g('nuPromptModal');
		if (!m) {
			m = document.createElement('div');
			m.id = 'nuPromptModal'; m.className = 'modal fade'; m.tabIndex = -1;
			m.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
				+ '<div class="modal-header py-2"><h2 class="modal-title fs-6" id="nuPromptTitle"></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
				+ '<div class="modal-body"><div id="nuPromptText" class="mb-2"></div><input class="form-control" id="prompt_value1" autocomplete="off"></div>'
				+ '<div class="modal-footer py-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="nuPromptCancel"></button><button type="button" class="btn btn-primary" id="nuPromptOk"></button></div>'
				+ '</div></div>';
			document.body.appendChild(m);
		}
		$('#nuPromptTitle').html(caption || '');
		$('#nuPromptText').html(text || '');
		$('#nuPromptOk').text(M.ok || 'OK');
		$('#nuPromptCancel').text(M.cancel || 'Cancel');
		var input = g('prompt_value1');
		input.value = defaultValue === undefined || defaultValue === null ? '' : defaultValue;
		var done = false, modal = nuBs.modal(m);
		var finish = function (ok) { if (done) return; done = true; modal.hide(); try { cb(ok ? input.value : null, ok); } catch (e) { console.error(e); } };
		$('#nuPromptOk').off('click').on('click', function () { finish(true); });
		$(input).off('keydown.nuPrompt').on('keydown.nuPrompt', function (e) { if (e.key === 'Enter') { e.preventDefault(); finish(true); } });
		$(m).off('hidden.bs.modal.nuPrompt').on('hidden.bs.modal.nuPrompt', function () { finish(false); });
		$(m).off('shown.bs.modal.nuPrompt').on('shown.bs.modal.nuPrompt', function () { input.focus(); input.select(); });
		modal.show();
	});

	// =================================================================== selects
	function sel(id) { return typeof id === 'object' ? $(id) : $('#' + id); }
	function selChanged($s) { $s.trigger('change'); var el = $s[0]; if (el && el.tomselect) el.tomselect.sync(); }
	api('nuSelectRemoveOption', function (id, value, byText) {
		var $s = sel(id);
		$s.find('option').filter(function () { return byText ? $(this).text().indexOf(String(value)) >= 0 : this.value === String(value); }).remove();
		if ($s[0] && $s[0].tomselect) $s[0].tomselect.sync();
		return $s;
	});
	api('nuSelectSelectAll', function (id, value) {
		var $s = sel(id);
		$s.find('option').filter(function () { return this.value !== '' || $(this).text().trim() !== ''; }).prop('selected', value !== false);
		selChanged($s);
	});
	api('nuSelectSelectedInfo', function (id) {
		var o = {values: [], texts: []};
		sel(id).find('option:selected').each(function () { if (this.value !== '') { o.values.push(this.value); o.texts.push($(this).text()); } });
		return o;
	});
	api('nuSelectSelectedValueArray', function (id) { return nuSelectSelectedInfo(id).values; });
	api('nuSelectSelectedTextArray', function (id) { return nuSelectSelectedInfo(id).texts; });
	api('nuSelectSetIndex', function (id, i) { var $s = sel(id); $s.prop('selectedIndex', i); selChanged($s); });
	api('nuSelectToArray', function (id, type, selectedOnly) {
		type = type || 'value';
		var out = [];
		sel(id).find(selectedOnly === false ? 'option' : 'option:selected').each(function () {
			var t = $(this).text();
			if (t.trim() === '') return;
			if (type === 'text') out.push(t); else if (type === 'value') out.push(this.value); else if (type === 'index') out.push($(this).index() + 1);
			else throw new Error('nuSelectToArray: unknown type "' + type + '"');
		});
		return out;
	});
	api('nuSelectRemoveMultiple', function (id) { $(id ? '#' + id : 'select').filter('[multiple]').prop('multiple', false).attr('size', '5'); });
	api('nuSelectMultiWithoutCtrl', function (id, active) {
		var opts = $((id ? '#' + id : 'select') + '[multiple] option');
		opts.off('mousedown.nuNoCtrl');
		if (active === false) return;
		opts.on('mousedown.nuNoCtrl', function (e) {
			if (e.shiftKey) return;
			e.preventDefault();
			var s = this.parentElement, top = s.scrollTop;
			this.selected = !this.selected;
			s.scrollTop = top;
			$(s).trigger('change');
		});
	});
	api('nuSelectAddEnglishOption', function (id) {
		var $s = $('#' + id);
		if ($s.val() === '') $s.append($('<option>', {value: 1, text: nuTranslate('English'), disabled: true, selected: true, hidden: true}));
	});

	// =================================================================== tabs
	function tabIndexByTitle(title) {
		var t = String(title).replace(/\|/g, '').trim().toLowerCase(), found = -1;
		$('.nuTab').each(function () {
			var x = $(this).text().trim().toLowerCase();
			if (found < 0 && (x === t || x === String(nuTranslate(title)).trim().toLowerCase())) found = +this.getAttribute('data-nu-tab');
		});
		return found;
	}
	function hideTabsIfOne() { if ($('.nuTab:visible').length <= 1) $('.nuTab').closest('.nav, .nuTabHolder').toggleClass('d-none', $('.nuTab').length <= 1); }
	api('nuGetTabIndexByTitle', tabIndexByTitle);
	api('nuSelectTabById', function (id) { var t = $('.nuTab[data-nu-tab-id="' + id + '"]')[0]; if (t) nuSelectTab(t); });
	api('nuSelectTabByTitle', function (title) { var i = tabIndexByTitle(title); if (i > -1) nuSelectTab(i); });
	api('nuGetSelectedTabId', function () { return nuSelectedTabId(); });
	api('nuSelectedTab', function () { var n = nuSelectedTabNumber(); return n === null ? null : $('#nuTab' + n); });
	api('nuSelectNextTab', function (step, includeInvisible) {
		var cur = nuSelectedTabNumber();
		if (cur === null) return;
		for (var n = cur + step, t; (t = g('nuTab' + n)); n += step) {
			if (includeInvisible || $(t).closest('li').is(':visible')) { nuSelectTab(n); return; }
		}
	});
	api('nuRemoveTabById', function (id) { var t = $('.nuTab[data-nu-tab-id="' + id + '"]'); if (t.length) nuRemoveTabs(+t.attr('data-nu-tab')); hideTabsIfOne(); });
	api('nuRemoveTabByTitle', function (title) { var i = tabIndexByTitle(title); if (i > -1) nuRemoveTabs(i); hideTabsIfOne(); });
	// nuBuilder: hides the tab bar when only one tab is visible (setting nuHideTabTitleIfOnlyOne: everyone | user | globeadmin)
	api('nuSetHideTabIfOnlyOne', function (who) {
		who = String(who || (window.nuUXOptions || {}).nuHideTabTitleIfOnlyOne || 'everyone').toLowerCase();
		var hide = who === 'everyone' || (who === 'user' && !nuGlobalAccess()) || (who === 'globeadmin' && nuGlobalAccess());
		if (hide && $('.nuTab').filter(function () { return $(this).closest('li').is(':visible'); }).length === 1) $('.nuTab').closest('ul').addClass('d-none');
	});

	// =================================================================== subforms
	function sfRows(sf) { return $('#' + sf + ' .nuSubformBody > .nuSubformRow'); }
	function rowIndexOf(sf, rowIndex) {
		var n = sfRows(sf).length;
		if (!n) return null;
		if (rowIndex === undefined) rowIndex = nuCurrentRow();
		return rowIndex === -1 ? n - 1 : rowIndex;
	}
	api('nuSubformGetObjectId', function (sf, field, rowIndex) {
		var r = rowIndexOf(sf, rowIndex);
		return r === null ? null : sf + nuPad3(r) + field;
	});
	api('nuSubformGetValue', function (sf, field, options) {
		options = options || {};
		var id = nuSubformGetObjectId(sf, field, options.rowIndex);
		return id ? nuGetValue(id, options.method || 'value') : null;
	});
	api('nuSubformSetValue', function (sf, field, value, options) {
		options = options || {};
		var id = nuSubformGetObjectId(sf, field, options.rowIndex);
		if (!id) return;
		if (options.onlyIfBlank && nuGetValue(id, options.method || 'value') !== '') return;
		nuSetValue(id, value, options.method || 'value', options.change !== false);
	});
	api('nuSubformFocusLastRow', function (sf, field) {
		var o = nuSubformObject(sf), f = field === undefined ? o.fields[1] : field;
		var el = g(sf + nuPad3(o.rows.length - 1) + f);
		if (el) el.focus();
	});
	api('nuAddSubformRow', function (t) {                 // t: an element of the subform (nuBuilder: its last row)
		var sf = $(t).closest('[data-nu-subform]').attr('data-nu-subform') || (typeof t === 'string' ? t : '');
		return sf ? nuAddRow(sf) : null;
	});
	api('nuSubformHide', function (sf, field) {
		if (!field) { nuHide(sf); return; }
		sfRows(sf).each(function () { nuHide(this.getAttribute('data-nu-prefix') + field); });
	});
	api('nuSubformShow', function (sf, field, show) {
		if (!field) { nuShow(sf, true, false); return; }
		sfRows(sf).each(function () { nuShow(this.getAttribute('data-nu-prefix') + field, show, false); });
	});
	function isGrid(sf) { return $('#' + sf + ' .nuSubformTable').length > 0; }        // grid (table) or form layout (one card per row)
	api('nuSubformHideColumns', function (sf, columns) { (columns || []).forEach(function (c) { if (isGrid(sf)) nuSubformHideColumn(sf, c); else nuSubformHide(sf, c); }); });
	api('nuSubformShowColumn', function (sf, field) {
		if (!isGrid(sf)) { nuSubformShow(sf, field); return; }
		var i = $('#title_' + sf + field).index();
		if (i >= 0) $('#' + sf + ' tr').each(function () { $(this).children().eq(i).show(); });
	});
	api('nuSubformHideHeader', function (sf) { $('#' + sf + ' thead').hide(); });
	api('nuSubformSetHeight', function (sf, height, minHeight, maxHeight) {
		var div = $('#' + sf + 'scrollDiv');
		if (div.data('nu-org-height') !== undefined && div.data('nu-org-height') !== null) {     // second call: back to the original height
			div.css('max-height', div.data('nu-org-height')).data('nu-org-height', null);
			return;
		}
		div.data('nu-org-height', div.css('max-height'));
		var h = height === undefined || height === null ? window.innerHeight - div[0].getBoundingClientRect().top - 50 : height;
		if (maxHeight !== undefined && h > maxHeight) h = maxHeight;
		if (minHeight !== undefined && h < minHeight) h = minHeight;
		div.css('max-height', h + 'px');
	});
	api('nuSubformTitleArray', function (sf) {
		var t = $('#' + sf + ' .nuSubformTitle').map(function () { return this.getAttribute('data-nu-field'); }).get();
		return t.length ? t : (((N.subforms || {})[sf] || {}).fields || []).slice();
	});
	function csvCell(v) {
		var s = v === null || v === undefined ? '' : (v instanceof Date ? v.toLocaleString() : String(v));
		s = s.replace(/"/g, '""');
		return /("|,|\n)/.test(s) ? '"' + s + '"' : s;
	}
	api('nuSubformRowToSeparatedString', function (rows, d, includeId) {
		return rows.map(function (r) { return r.slice(includeId ? 0 : 1).map(csvCell).join(d); }).join('\n') + (rows.length ? '\n' : '');
	});
	api('nuSubformHeaderToSeparatedString', function (fields, d, includeId) { return fields.slice(includeId ? 0 : 1).join(d) + '\n'; });
	api('nuSubformToClipboard', function (sf, delimiter, includeHeader, includeId) {
		var o = nuSubformObject(sf), d = delimiter || '\t';
		var rows = o.rows.map(function (r) { return r.slice(0, -1); });               // without the nuDelete column
		var fields = o.fields.slice(0, -1);
		return nuCopyToClipboard((includeHeader ? nuSubformHeaderToSeparatedString(fields, d, !!includeId) : '') + nuSubformRowToSeparatedString(rows, d, !!includeId));
	});
	api('nuSubformMoveFocus', function (active, d) {
		var $a = $(active), p = $a.attr('data-nu-prefix');
		if (!p) return false;
		var sf = p.slice(0, -3), f = $a.attr('data-nu-field');
		var next = $('#' + sf + nuPad3(Number(p.slice(-3)) + d) + f);
		if (next.length === 1 && !next.prop('disabled')) next.focus();
		return true;
	});
	// sorts the rows by a column (index in nuSubformObject().fields); the blank new row stays last; a second call reverses
	api('nuSortSubform', function (sf, c, e) {
		if (e && e.target && !$(e.target).closest('.nuSubformTitle').length) return;
		var o = nuSubformObject(sf), f = typeof c === 'number' ? o.fields[c] : c;
		var body = $('#' + sf + ' .nuSubformBody'), rows = body.children('.nuSubformRow').not('.nuNewRow').get();
		var dir = body.data('nu-sort') === f + 'asc' ? -1 : 1;
		var val = function (r) {
			var el = g(r.getAttribute('data-nu-prefix') + f);
			if (!el) return '';
			var code = g(el.id + 'code'), v = code ? code.value : N.rawValue(el);
			return v === '' || isNaN(v) ? String(v).toLowerCase() : Number(v);
		};
		rows.sort(function (a, b) { var x = val(a), y = val(b); return (x > y ? 1 : x < y ? -1 : 0) * dir; });
		var last = body.children('.nuNewRow');
		rows.forEach(function (r) { body.append(r); });
		body.append(last);
		body.data('nu-sort', f + (dir === 1 ? 'asc' : 'desc'));
	});
	// paste tab separated cells (e.g. from Excel) into the subform, starting at the focused cell
	api('nuGetClipboardText', function (e) {
		var cb = e.clipboardData || (e.originalEvent && e.originalEvent.clipboardData) || window.clipboardData;
		return cb && cb.getData ? cb.getData(window.clipboardData ? 'Text' : 'text/plain') : '';
	});
	api('nuGetClipboardRows', function (t) { return String(t).split('\n').map(function (r) { return r.split('\t'); }); });
	api('nuGetClipboardJson', function (rows) {
		var out = [];
		for (var i = 0; i < rows.length - 1 || (i === 0 && rows.length === 1); i++) {
			var item = {};
			rows[i].forEach(function (v, j) { if (v !== '\r') item[j] = v.replace(/\r$/, ''); });
			out.push(item);
		}
		return out;
	});
	api('nuSubformPaste', function (e, json) {
		var $t = $(e.target), p = $t.attr('data-nu-prefix');
		if (!p || !json.length) return [];
		var sf = p.slice(0, -3), r0 = Number(p.slice(-3)), o = nuSubformObject(sf), c0 = o.fields.indexOf($t.attr('data-nu-field'));
		var cols = Object.keys(json[0]).length, changed = [];
		for (var r = 0; r < json.length; r++) {
			while (!g(sf + nuPad3(r0 + r) + o.fields[c0]) && nuAddRow(sf)) { /* add rows as needed */ }
			for (var c = 0; c < cols; c++) {
				var dest = $('#' + sf + nuPad3(r0 + r) + o.fields[c0 + c]);
				if (!dest.length || dest.prop('readonly') || dest.prop('disabled')) continue;
				dest.attr('data-prevalue', dest.val()).val(json[r][c]).trigger('change');
				changed.push(dest);
			}
		}
		return changed;
	});
	api('nuSubformUndoPaste', function (t) {
		if (!window.confirm(nuTranslate('Undo the last paste? (The values before the insertion will be restored)?'))) return;
		$('[data-prevalue]').each(function () { $(this).val($(this).attr('data-prevalue')).removeAttr('data-prevalue').trigger('change'); });
		if (t && t.id) nuHide(t.id);
	});
	api('nuSubformEnableMultiPaste', function (sf, selector, undoButton, callback) {
		$(selector || '#' + sf + ' [data-nu-field]').not('.nuReadonly').on('paste', function (e) {
			var text = nuGetClipboardText(e);
			if (text.indexOf('\t') < 0 && text.indexOf('\n') < 0) return;
			e.stopPropagation(); e.preventDefault();
			if (!window.confirm(nuTranslate('Paste Data? Existing data might get overwritten'))) return;
			$('#' + sf + ' [data-prevalue]').removeAttr('data-prevalue');
			var mod = nuSubformPaste(e, nuGetClipboardJson(nuGetClipboardRows(text)));
			if (undoButton) nuShow(undoButton);
			if (callback) callback(mod);
		});
	});

	// =================================================================== browse
	function browseUrl(params) {
		var u = new URL(window.location.href);
		Object.keys(params).forEach(function (k) { if (params[k] === null) u.searchParams.delete(k); else u.searchParams.set(k, params[k]); });
		return u.toString();
	}
	api('nuSetBrowseTitle', function (t) { nuSetTitle(t); });
	api('nuSetBrowseNoDataMessage', function (s) { $('.nuBrowseNoData').html(nuTranslate(s)); });
	api('nuSetBrowseNoSearchResultsMessage', function (s) { $('.nuBrowseNoResults').html(nuTranslate(s)); });
	api('nuSetBrowseColumns', function (widths) { (widths || []).forEach(function (w, i) { nuSetBrowseColumnSize(i, w); }); });
	api('nuHideSearchField', function () { $('.nuBrowseSearch').hide(); });
	api('nuDisableSearchField', function () { $('#nuSearchField').prop('disabled', true); $('.nuBrowseSearch button[type=submit]').prop('disabled', true); });
	api('nuSortBrowse', function (column) {
		var b = N.browse || {}, dir = String(b.sort) === String(column) && b.dir === 'asc' ? 'desc' : 'asc';
		window.location.href = browseUrl({sort: column, dir: dir, pg: null});
	});
	api('nuGetPage', function (n) {
		var b = N.browse || {};
		n = parseInt(n === undefined ? 1 : n, 10) || 1;
		if (n < 1 || (b.pages && n > b.pages)) return;
		window.location.href = browseUrl({pg: n});
	});
	api('nuPrintAction', function () { var p = g('nuPrintButton'); if (p) window.open(p.href, '_blank'); });
	api('nuAddAction', function () {
		if (nuFormType() !== 'browse') return;
		if (window.nuOnAddAction && window.nuOnAddAction(N.browse) === false) return;
		nuForm(N.form.redirect || N.form.id, '-1');
	});
	if (!$.fn.nuHighlight) {
		$.fn.nuHighlight = function (pattern) {
			if (!pattern) return this;
			var fold = function (s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase(); };
			var p = fold(String(pattern));
			this.each(function () {
				var walk = function (node) {
					if (node.nodeType === 3) {
						var i = fold(node.data).indexOf(p);
						if (i < 0) return 0;
						var m = document.createElement('mark'); m.className = 'nuHighlight';
						var mid = node.splitText(i); mid.splitText(p.length);
						m.appendChild(mid.cloneNode(true)); mid.parentNode.replaceChild(m, mid);
						return 1;
					}
					if (node.nodeType === 1 && node.childNodes && !/(script|style|mark)/i.test(node.tagName)) {
						for (var k = 0; k < node.childNodes.length; k++) k += walk(node.childNodes[k]);
					}
					return 0;
				};
				walk(this);
			});
			return this;
		};
	}
	api('nuHighlightSearch', function () {
		var q = String((N.browse || {}).q || '');
		if (!q) return;
		q.split(' ').filter(function (a) { return a && a.charAt(0) !== '-'; }).sort(function (a, b) { return a.length - b.length; })
			.forEach(function (t) { $('td.nuCell').nuHighlight(t); });
	});

	// =================================================================== action buttons
	api('nuAddBackButton', function () {
		if ((N.breadcrumbs || []).length > 1) nuAddActionButton('Back', nuTranslate('Back'), function () { nuOpenPreviousBreadcrumb(); });
	});
	api('nuAddActionButtonSaveClose', function (caption) {
		var b = nuAddActionButton('SaveClose', nuTranslate(caption || 'Save & Close'), function () { nuSaveAction(true); });
		b.addClass('nuSaveButton');
		return b;
	});

	// =================================================================== jQuery helpers
	if (!$.fn.nuOnEnterKey) {
		$.fn.nuOnEnterKey = function (fn, preventDefault) {
			return this.each(function () { $(this).on('keydown', function (e) { if (e.key === 'Enter') { if (preventDefault) e.preventDefault(); fn.call(this, e); } }); });
		};
	}
	if (!$.fn.nuFocusWithoutScrolling) {
		$.fn.nuFocusWithoutScrolling = function () { var el = this[0]; if (el) el.focus({preventScroll: true}); return this; };
	}
})();
