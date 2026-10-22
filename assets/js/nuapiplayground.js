/* REST API playground (pages/nuapiplayground.php): token, request editor, response, saved requests, settings per login. */
(function () {
	'use strict';
	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	var D = {};
	try { D = JSON.parse($('#apgData').textContent); } catch (e) { return; }
	var T = D.t || {};
	function t(k, a) { var s = T[k] || k; return a === undefined ? s : s.replace('%s', a); }
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') e.textContent = attrs[k]; else if (k === 'class') e.className = attrs[k]; else e.setAttribute(k, attrs[k]);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	var BADGE = {GET: 'text-bg-success', POST: 'text-bg-primary', PUT: 'text-bg-warning', PATCH: 'text-bg-info', DELETE: 'text-bg-danger'};

	var state = {
		token: null, tokenUntil: 0, tokenClient: '',
		current: '',                    // id of the loaded saved request
		requests: D.requests || [],
		settings: D.settings || {},
		auto: {},                       // last_id, last_etag, last_status, last_location
		etags: {},                      // resource path → ETag (If-Match)
		last: null                      // last request sent (for curl)
	};
	var client = $('#apgClient'), scope = $('#apgScope'), method = $('#apgMethod'), path = $('#apgPath'), body = $('#apgBody');

	// ---------------------------------------------------------------- server calls (same page, JSON + CSRF)
	function call(action, data) {
		return fetch(D.url, {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf},
			body: JSON.stringify(Object.assign({action: action}, data || {}))
		}).then(function (r) {
			return r.json().catch(function () { throw new Error(r.redirected || r.status === 200 ? t('apg_session') : 'HTTP ' + r.status); });
		});
	}
	function toast(msg, ok) {
		var box = $('#apgToast');
		if (!box) {
			box = el('div', {id: 'apgToast', class: 'position-fixed bottom-0 end-0 p-3 apgToast'});
			document.body.appendChild(box);
		}
		var a = el('div', {class: 'alert py-2 px-3 small shadow-sm mb-2 ' + (ok === false ? 'alert-danger' : 'alert-success'), role: ok === false ? 'alert' : 'status', text: msg});
		box.appendChild(a);
		setTimeout(function () { a.remove(); }, ok === false ? 6000 : 2200);
	}

	// ---------------------------------------------------------------- settings (client, scope, If-Match, variables) per login
	function clientOf(ref) { return (D.clients || []).filter(function (c) { return c.ref === ref; })[0] || null; }
	function parseVars(text) {
		var o = {};
		String(text || '').split(/\r?\n/).forEach(function (l) {
			var m = l.match(/^\s*(\w{1,40})\s*=(.*)$/);
			if (m) o[m[1]] = m[2].trim();
		});
		return o;
	}
	function varsText(o) { return Object.keys(o || {}).map(function (k) { return k + '=' + o[k]; }).join('\n'); }
	function currentSettings() {
		return {client_ref: client.value, scope: scope.value.trim(), auto_etag: $('#apgAutoEtag').checked, vars: parseVars($('#apgVars').value)};
	}
	var saveTimer = null;
	function saveSettings() {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(function () {
			call('settings', {settings: currentSettings()}).then(function (j) { if (j.ok) state.settings = j.settings; }).catch(function () { /* ignore */ });
		}, 600);
	}
	function applySettings(s) {
		if (s.client_ref && clientOf(s.client_ref)) client.value = s.client_ref;
		scope.value = s.scope || '';
		$('#apgAutoEtag').checked = s.auto_etag !== false;
		$('#apgVars').value = varsText(s.vars);
	}

	function showClient() {
		var c = clientOf(client.value), dl = $('#apgScopes');
		dl.textContent = '';
		var info = $('#apgClientInfo');
		info.textContent = '';
		if (!c) return;
		c.scopes.forEach(function (s) { dl.appendChild(el('option', {value: s})); });
		dl.appendChild(el('option', {value: c.scopes.join(' ')}));
		info.appendChild(el('code', {class: 'small', text: c.client_id}));
		info.appendChild(document.createTextNode(' · ' + (c.user || '–') + (c.level ? ' (' + c.level + ')' : '') + ' · ' + c.scopes.join(' ')));
		if (!c.active) info.appendChild(el('span', {class: 'badge text-bg-secondary ms-1', text: t('apg_inactive')}));
		if (c.ip) info.appendChild(el('span', {class: 'd-block text-warning-emphasis', text: t('apg_ip_hint', c.ip)}));
	}

	// ---------------------------------------------------------------- token
	function tokenState() {
		var b = $('#apgTokenState');
		if (!state.token) { b.className = 'badge text-bg-light border'; b.textContent = t('apg_token_none'); return; }
		var left = state.tokenUntil - Date.now();
		if (left <= 0) { b.className = 'badge text-bg-warning'; b.textContent = t('apg_token_expired'); return; }
		var d = new Date(state.tokenUntil);
		b.className = 'badge text-bg-success';
		b.textContent = t('apg_token_until', ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2)) + ' · ' + (state.tokenScope || '');
	}
	setInterval(tokenState, 15000);
	function setToken(tok) {
		state.token = tok.access_token;
		state.tokenUntil = Date.now() + (tok.expires_in || 900) * 1000 - 5000;
		state.tokenScope = tok.scope || '';
		state.tokenClient = client.value;
		tokenState();
	}
	function getToken() {
		var btn = $('#apgToken');
		btn.disabled = true;
		return call('token', {client_ref: client.value, scope: scope.value.trim()}).then(function (j) {
			if (!j.ok) throw new Error(j.error || 'Error');
			setToken(j.token);
			return state.token;
		}).catch(function (e) { toast(e.message, false); throw e; }).finally(function () { btn.disabled = false; });
	}
	$('#apgToken').addEventListener('click', function () { getToken().catch(function () { /* shown */ }); });

	// real OAuth flow: client_id + secret from the browser to api.php/v1/token (the secret is not stored)
	$('#apgTokenSecret').addEventListener('click', function () {
		var c = clientOf(client.value), secret = $('#apgSecret').value.trim();
		if (!c || !secret) { $('#apgSecret').focus(); return; }
		var form = 'grant_type=client_credentials' + (scope.value.trim() ? '&scope=' + encodeURIComponent(scope.value.trim()) : '');
		var t0 = performance.now();
		fetch(D.api + '/v1/token', {
			method: 'POST', credentials: 'omit', cache: 'no-store',
			headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Authorization': 'Basic ' + btoa(c.client_id + ':' + secret)},
			body: form
		}).then(function (r) {
			return r.text().then(function (txt) {
				showResponse(r, txt, performance.now() - t0, {method: 'POST', url: D.api + '/v1/token', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: form, basic: c.client_id});
				var j = null;
				try { j = JSON.parse(txt); } catch (e) { /* ignore */ }
				if (r.ok && j && j.access_token) { setToken(j); $('#apgSecret').value = ''; }
			});
		}).catch(function (e) { toast(t('apg_net_error') + ' ' + e.message, false); });
	});

	client.addEventListener('change', function () {
		state.token = null; tokenState(); showClient(); saveSettings();
	});
	scope.addEventListener('change', function () { state.token = null; tokenState(); saveSettings(); });
	$('#apgAutoEtag').addEventListener('change', saveSettings);
	$('#apgVars').addEventListener('input', saveSettings);

	// ---------------------------------------------------------------- headers editor
	function headerRow(h) {
		h = h || {name: '', value: '', on: true};
		var row = el('div', {class: 'input-group input-group-sm mb-1 apgHeader'});
		var chk = el('input', {class: 'form-check-input mt-0', type: 'checkbox', 'aria-label': 'on'});
		chk.checked = h.on !== false;
		row.appendChild(el('span', {class: 'input-group-text'}, [chk]));
		var n = el('input', {class: 'form-control font-monospace apgHName', placeholder: t('apg_header_name'), 'aria-label': t('apg_header_name'), list: 'apgHeaderNames'});
		n.value = h.name || '';
		var v = el('input', {class: 'form-control font-monospace apgHValue', placeholder: t('apg_header_value'), 'aria-label': t('apg_header_value')});
		v.value = h.value || '';
		var rm = el('button', {class: 'btn btn-outline-secondary', type: 'button', title: t('remove'), 'aria-label': t('remove')}, [el('i', {class: 'bi bi-x-lg'})]);
		rm.addEventListener('click', function () { row.remove(); countHeaders(); });
		row.appendChild(n); row.appendChild(v); row.appendChild(rm);
		chk.addEventListener('change', countHeaders);
		n.addEventListener('input', countHeaders);
		$('#apgHeaders').appendChild(row);
		countHeaders();
	}
	var names = el('datalist', {id: 'apgHeaderNames'});
	['If-Match', 'Accept', 'Accept-Language', 'Content-Type', 'X-Request-Id'].forEach(function (n) { names.appendChild(el('option', {value: n})); });
	document.body.appendChild(names);
	function headersOf() {
		return $$('.apgHeader').map(function (r) {
			return {name: $('.apgHName', r).value.trim(), value: $('.apgHValue', r).value, on: $('input[type=checkbox]', r).checked};
		}).filter(function (h) { return h.name !== ''; });
	}
	function countHeaders() { $('#apgHeadCount').textContent = headersOf().filter(function (h) { return h.on; }).length; }
	$('#apgAddHeader').addEventListener('click', function () { headerRow(); $$('.apgHName').slice(-1)[0].focus(); });

	// ---------------------------------------------------------------- body: JSON check / format
	function checkJson() {
		var s = body.value.trim(), out = $('#apgJsonState');
		if (s === '') { out.textContent = ''; return true; }
		try { JSON.parse(substitute(s, true)); out.textContent = ''; return true; } catch (e) { out.textContent = t('apg_json_invalid') + ' ' + e.message; return false; }
	}
	body.addEventListener('input', function () { clearTimeout(body._t); body._t = setTimeout(checkJson, 400); });
	$('#apgFormat').addEventListener('click', function () {
		try { body.value = JSON.stringify(JSON.parse(body.value), null, 2); checkJson(); } catch (e) { checkJson(); }
	});

	// ---------------------------------------------------------------- variables {{name}}
	function allVars() { return Object.assign({}, state.auto, parseVars($('#apgVars').value)); }
	function substitute(s, forCheck) {
		var v = allVars();
		return String(s).replace(/\{\{\s*(\w+)\s*\}\}/g, function (m, k) { return v[k] !== undefined ? v[k] : (forCheck ? '' : m); });
	}
	function showAutoVars() {
		var keys = Object.keys(state.auto).filter(function (k) { return state.auto[k] !== ''; });
		$('#apgAutoVars').textContent = keys.length ? t('apg_var_auto') + ' ' + keys.map(function (k) { return '{{' + k + '}}'; }).join(' ') : '';
	}
	function resourcePath(p) { return String(p).split('?')[0].replace(/\/+$/, ''); }

	// ---------------------------------------------------------------- send
	function send() {
		if (!client.value) return;
		var p = substitute(path.value.trim());
		if (p.charAt(0) !== '/') p = '/' + p;
		if (/\{\{\w+\}\}/.test(p)) { $('[data-for="path"]').textContent = t('apg_var_missing', (p.match(/\{\{\w+\}\}/) || [''])[0]); return; }
		$('[data-for="path"]').textContent = '';
		var m = method.value, b = body.value.trim(), headers = {};
		headersOf().forEach(function (h) { if (h.on) headers[h.name] = substitute(h.value); });
		if (m !== 'GET' && m !== 'DELETE' && b !== '') {
			b = substitute(b);
			try { JSON.parse(b); } catch (e) { checkJson(); return; }
			if (!Object.keys(headers).some(function (k) { return k.toLowerCase() === 'content-type'; })) headers['Content-Type'] = 'application/json';
		} else if (m === 'GET') b = '';
		else if (b !== '') { b = substitute(b); headers['Content-Type'] = headers['Content-Type'] || 'application/json'; }
		var hasIfMatch = Object.keys(headers).some(function (k) { return k.toLowerCase() === 'if-match'; });
		if (!hasIfMatch && $('#apgAutoEtag').checked && /^(PUT|PATCH|DELETE)$/.test(m) && state.etags[resourcePath(p)]) headers['If-Match'] = state.etags[resourcePath(p)];

		var ready = state.token && state.tokenUntil > Date.now() && state.tokenClient === client.value ? Promise.resolve(state.token) : getToken();
		var btn = $('#apgSend');
		btn.disabled = true;
		$('#apgStatus').className = 'badge text-bg-light border';
		$('#apgStatus').textContent = t('apg_sending');
		ready.then(function (tok) {
			var h = Object.assign({'Authorization': 'Bearer ' + tok, 'Accept': 'application/json'}, headers);
			var t0 = performance.now();
			var req = {method: m, url: D.api + p, headers: headers, body: b};
			return fetch(D.api + p, {method: m, headers: h, body: b === '' ? undefined : b, credentials: 'omit', cache: 'no-store', redirect: 'manual'})
				.then(function (r) { return r.text().then(function (txt) { showResponse(r, txt, performance.now() - t0, req); learn(r, txt, p, m); }); });
		}).catch(function (e) {
			if (e && e.message) { $('#apgStatus').className = 'badge text-bg-danger'; $('#apgStatus').textContent = t('apg_net_error'); $('#apgResBody').textContent = e.message; }
		}).finally(function () { btn.disabled = false; });
	}
	$('#apgForm').addEventListener('submit', function (e) { e.preventDefault(); send(); });
	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && $('#apg').contains(document.activeElement)) { e.preventDefault(); send(); }
	});

	// remembers id, ETag and Location of the last response as variables
	function learn(r, txt, p, m) {
		var etag = r.headers.get('ETag') || '', loc = r.headers.get('Location') || '', j = null;
		try { j = JSON.parse(txt); } catch (e) { /* ignore */ }
		state.auto.last_status = String(r.status);
		if (j && typeof j === 'object' && !Array.isArray(j) && j.id !== undefined && j.id !== null) state.auto.last_id = String(j.id);
		else if (loc) state.auto.last_id = loc.split('/').pop();
		if (etag) {
			state.auto.last_etag = etag;
			var res = loc ? resourcePath(loc.replace(/^.*?\/v1\//, '/v1/')) : resourcePath(p);
			state.etags[res] = etag;
		}
		if (m === 'DELETE' && r.ok) delete state.etags[resourcePath(p)];
		showAutoVars();
	}

	function pretty(txt) {
		try { return JSON.stringify(JSON.parse(txt), null, 2); } catch (e) { return txt; }
	}
	function showResponse(r, txt, ms, req) {
		var cls = r.status >= 500 ? 'text-bg-danger' : r.status >= 400 ? 'text-bg-warning' : r.status >= 300 ? 'text-bg-info' : 'text-bg-success';
		var st = $('#apgStatus');
		st.className = 'badge ' + cls;
		st.textContent = r.status + ' ' + (r.statusText || '');
		$('#apgTime').textContent = Math.round(ms) + ' ms · ' + new Blob([txt]).size + ' B';
		$('#apgResBody').textContent = r.status === 204 ? '(204 No Content)' : pretty(txt);
		var hs = [];
		r.headers.forEach(function (v, k) { hs.push(k + ': ' + v); });
		$('#apgResHead').textContent = hs.sort().join('\n');
		state.last = req;
		$('#apgCurlOut').hidden = true;
	}

	// ---------------------------------------------------------------- copy as curl / copy body
	function q(s) { return "'" + String(s).replace(/'/g, "'\\''") + "'"; }
	function curl() {
		var r = state.last;
		var m = method.value, p = substitute(path.value.trim()), b = body.value.trim();
		var headers = {};
		if (r) { m = r.method; p = r.url.replace(D.api, ''); b = r.body; headers = r.headers; }
		var lines = ['curl -s -X ' + m + ' ' + q(D.base + p)];
		if (r && r.basic) lines.push("  -u " + q(r.basic + ':<client_secret>'));
		else lines.push("  -H 'Authorization: Bearer <access_token>'");
		Object.keys(headers).forEach(function (k) { lines.push('  -H ' + q(k + ': ' + headers[k])); });
		if (b) lines.push('  --data ' + q(b));
		return lines.join(' \\\n');
	}
	function copy(text, btn) {
		var ok = function () { var i = $('i', btn); if (i) { var c = i.className; i.className = 'bi bi-check2'; setTimeout(function () { i.className = c; }, 1500); } };
		if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(ok, function () { /* ignore */ });
	}
	$('#apgCurl').addEventListener('click', function () {
		var out = $('#apgCurlOut'), s = curl();
		out.textContent = s;
		out.hidden = false;
		copy(s, this);
	});
	$('#apgCopyBody').addEventListener('click', function () { copy($('#apgResBody').textContent, this); });

	// ---------------------------------------------------------------- saved requests
	function renderList() {
		var list = $('#apgSavedList'), find = $('#apgFind').value.trim().toLowerCase(), folders = {};
		list.textContent = '';
		var rows = state.requests.filter(function (r) {
			return !find || (r.name + ' ' + r.folder + ' ' + r.path + ' ' + r.method).toLowerCase().indexOf(find) >= 0;
		});
		if (!rows.length) list.appendChild(el('div', {class: 'list-group-item text-body-secondary', text: t('apg_no_saved')}));
		var lastFolder = null;
		rows.forEach(function (r) {
			folders[r.folder] = true;
			if (r.folder !== lastFolder) {
				lastFolder = r.folder;
				list.appendChild(el('div', {class: 'list-group-item bg-body-tertiary py-1 fw-semibold text-body-secondary apgFolderHead'}, [el('i', {class: 'bi bi-folder2 me-1'}), r.folder || t('apg_no_folder')]));
			}
			var b = el('button', {type: 'button', class: 'list-group-item list-group-item-action apgItem' + (r.id === state.current ? ' active' : ''), 'data-id': r.id}, [
				el('span', {class: 'badge apgMeth ' + (BADGE[r.method] || 'text-bg-secondary'), text: r.method}),
				el('span', {class: 'apgName', text: r.name}),
				el('span', {class: 'd-block font-monospace text-truncate apgPath' + (r.id === state.current ? '' : ' text-body-secondary'), text: r.path})
			]);
			if (r.id === state.current) b.setAttribute('aria-current', 'true');
			b.addEventListener('click', function () { load(r); });
			list.appendChild(b);
		});
		var dl = $('#apgFolders');
		dl.textContent = '';
		state.requests.forEach(function (r) { if (r.folder) folders[r.folder] = true; });
		Object.keys(folders).filter(Boolean).sort().forEach(function (f) { dl.appendChild(el('option', {value: f})); });
	}
	$('#apgFind').addEventListener('input', renderList);

	function fill(r) {
		method.value = r.method || 'GET';
		path.value = r.path || '';
		body.value = r.body || '';
		$('#apgHeaders').textContent = '';
		(r.headers || []).forEach(headerRow);
		countHeaders();
		checkJson();
	}
	function load(r) {
		state.current = r.id;
		$('#apgName').value = r.name;
		$('#apgFolder').value = r.folder || '';
		fill(r);
		var s = r.settings || {};
		if (s.client_ref && clientOf(s.client_ref) && s.client_ref !== client.value) { client.value = s.client_ref; state.token = null; showClient(); tokenState(); }
		if (s.scope !== undefined && s.scope !== scope.value) { scope.value = s.scope; state.token = null; tokenState(); }
		$('#apgDelete').disabled = false;
		renderList();
	}
	function clearForm() {
		state.current = '';
		$('#apgName').value = ''; $('#apgFolder').value = '';
		fill({method: 'GET', path: '/v1/me', body: '', headers: []});
		$('#apgDelete').disabled = true;
		renderList();
		$('#apgName').focus();
	}
	$('#apgNew').addEventListener('click', clearForm);

	$$('.apgEp').forEach(function (b) {
		b.addEventListener('click', function () {
			var e = D.endpoints[+b.getAttribute('data-ep')];
			state.current = '';
			$('#apgDelete').disabled = true;
			$('#apgName').value = e.label;
			fill({method: e.method, path: e.path, body: e.body || '', headers: []});
			renderList();
			path.focus();
		});
	});

	function save(asNew) {
		$$('#apgForm .is-invalid').forEach(function (x) { x.classList.remove('is-invalid'); });
		$$('#apgForm [data-for]').forEach(function (x) { x.textContent = ''; });
		var req = {
			id: asNew ? '' : state.current, name: $('#apgName').value.trim(), folder: $('#apgFolder').value.trim(),
			method: method.value, path: path.value.trim(), headers: headersOf(), body: body.value,
			settings: {client_ref: client.value, scope: scope.value.trim(), auto_etag: $('#apgAutoEtag').checked}
		};
		call('save', {request: req}).then(function (j) {
			if (!j.ok) {
				Object.keys(j.errors || {}).forEach(function (k) {
					var f = {name: '#apgName', path: '#apgPath'}[k];
					if (f) $(f).classList.add('is-invalid');
					var fb = $('#apgForm [data-for="' + k + '"]');
					if (fb) fb.textContent = j.errors[k];
				});
				toast(j.error || 'Error', false);
				return;
			}
			state.requests = j.requests;
			state.current = j.request.id;
			$('#apgDelete').disabled = false;
			renderList();
			toast(t('apg_saved'));
		}).catch(function (e) { toast(e.message, false); });
	}
	$('#apgSave').addEventListener('click', function () { save(false); });
	$('#apgSaveNew').addEventListener('click', function () { save(true); });

	$('#apgDelete').addEventListener('click', function () {
		var r = state.requests.filter(function (x) { return x.id === state.current; })[0];
		if (!r) return;
		ask(t('apg_delete_confirm', r.name), function () {
			call('delete', {id: r.id}).then(function (j) {
				if (!j.ok) { toast(j.error || 'Error', false); return; }
				state.requests = j.requests;
				clearForm();
				toast(t('apg_deleted'));
			}).catch(function (e) { toast(e.message, false); });
		});
	});
	// the app's confirm dialog (#nuConfirm) when available
	function ask(text, then) {
		var m = $('#nuConfirm');
		if (!m || !nuBs.ready()) { if (window.confirm(text)) then(); return; }
		$('.js-confirm-text', m).textContent = text;
		var ok = $('.js-confirm-ok', m);
		if (ok) { ok.textContent = t('delete'); ok.className = 'btn btn-danger js-confirm-ok'; }
		var modal = nuBs.modal(m);
		var go = function () { ok.removeEventListener('click', go); modal.hide(); then(); };
		if (ok) ok.addEventListener('click', go);
		m.addEventListener('hidden.bs.modal', function () { if (ok) ok.removeEventListener('click', go); }, {once: true});
		modal.show();
	}

	// ---------------------------------------------------------------- start
	applySettings(state.settings);
	showClient();
	tokenState();
	renderList();
	countHeaders();
})();
