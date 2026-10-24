/*
 * CSV import wizard (pages/nuimport.php): 1 file + reading options + preview, 2 table + column mapping + options,
 * 3 check (complete trial run, rolled back) + import. Configurations can be saved / loaded as JSON.
 * All server calls: POST with the CSRF token, JSON answers {ok, …} or {ok: false, error}.
 */
(function () {
	'use strict';
	var app = document.getElementById('impApp');
	if (!app) return;
	var T = JSON.parse(app.getAttribute('data-texts') || '{}');
	var FORMATS = JSON.parse(app.getAttribute('data-formats') || '{}');
	var TABLES = JSON.parse(app.getAttribute('data-tables') || '[]');
	var USERS = 'zzzzsys_user', TARGET = app.getAttribute('data-target') || '';   // users (0.8.327): a target of its own, checked like the user form
	if (app.getAttribute('data-users') === '1') TABLES.unshift(USERS);
	var isUsers = function () { return S.table === USERS; };
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
	var csrf = $('input[name="_csrf"]', app).value;
	var tx = function (k) { var a = Array.prototype.slice.call(arguments, 1), s = T[k] || k, i = 0; return s.replace(/%[sd]/g, function () { return a[i++]; }); };
	var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };

	var S = {headers: [], rows: [], count: 0, dates: [], columns: [], mapping: {}, table: '', config: null, lastResult: null, step: 1};

	// ------------------------------------------------------------------ helpers
	function msg(text, type) {
		var box = $('#impMsg');
		box.innerHTML = text ? '<div class="alert alert-' + (type || 'info') + ' alert-dismissible fade show py-2" role="alert">' + esc(text)
			+ '<button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button></div>' : '';
	}
	function busy(on) {
		app.classList.toggle('impBusy', !!on);
		$$('button', app).forEach(function (b) { if (on) { b.dataset.wasDisabled = b.disabled ? '1' : ''; b.disabled = true; } else if (b.dataset.wasDisabled !== undefined) { b.disabled = b.dataset.wasDisabled === '1'; delete b.dataset.wasDisabled; } });
	}
	function read() {
		var d = $('#impDelimiter').value;
		return {delimiter: d === 'custom' ? $('#impDelimiterCustom').value : d, quote: $('#impQuote').value, header: $('#impHeader').checked ? '1' : '0'};
	}
	function options() {
		return {mode: $('#impMode').value, key: $('#impKey').value, decimal: $('#impDecimal').value, empty: $('#impEmpty').value,
			on_error: $('#impOnError').value, trim: $('#impTrim').checked ? '1' : '0', audit: $('#impAudit').checked ? '1' : '0', welcome: $('#impWelcome').checked ? '1' : '0'};
	}
	function call(action, data, file) {
		var fd = new FormData();
		fd.append('_csrf', csrf); fd.append('action', action);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]); });
		if (file) fd.append('file', file);
		return fetch('index.php?p=import', {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json(); }, function () { throw new Error(tx('imp_err_network')); })
			.then(function (j) { if (!j.ok) throw new Error(j.error || tx('imp_err_network')); return j; });
	}
	function step(n) {
		S.step = n;
		$$('.impStep', app).forEach(function (s) { s.classList.toggle('d-none', +s.dataset.step !== n); });
		$$('.impSteps li', app).forEach(function (li) { li.classList.toggle('active', +li.dataset.step === n); li.classList.toggle('done', +li.dataset.step < n); });
		window.scrollTo({top: 0, behavior: 'smooth'});
	}
	function fmtBytes(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

	// ------------------------------------------------------------------ step 1: file
	function showPreview(j) {
		S.headers = j.headers; S.rows = j.rows; S.count = j.count; S.dates = j.dates;
		if (j.decimal && !(S.config && S.config.options && S.config.options.decimal)) $('#impDecimal').value = j.decimal;   // from the numbers of the file
		var t = $('#impPreview');
		var head = '<thead><tr><th class="text-body-secondary">#</th>' + S.headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('') + '</tr></thead>';
		var body = '<tbody>' + S.rows.map(function (r, i) {
			return '<tr><td class="text-body-secondary">' + (i + 1) + '</td>' + S.headers.map(function (_, c) { return '<td>' + esc(r[c] !== undefined ? r[c] : '') + '</td>'; }).join('') + '</tr>';
		}).join('') + '</tbody>';
		t.innerHTML = head + body;
		$('#impRowCount').textContent = tx('imp_rows_found', S.count.toLocaleString(), S.headers.length);
	}
	function upload(file) {
		if (!file) return;
		msg('');
		busy(true);
		call('upload', {header: $('#impHeader').checked ? '1' : '0', quote: $('#impQuote').value}, file).then(function (j) {
			$('#impDelimiter').value = j.delimiter;
			$('#impDelimiterCustom').classList.add('d-none');
			$('#impFileInfo').innerHTML = '<i class="bi bi-file-earmark-text me-1"></i>' + esc(j.file.name) + ' <span class="small text-body-secondary fw-normal">· ' + fmtBytes(j.file.size) + ' · ' + esc(j.file.encoding) + '</span>';
			showPreview(j);
			$('#impFileCard').classList.remove('d-none');
			if (S.config) applyReadConfig();
		}).catch(function (e) { msg(e.message, 'danger'); }).finally(function () { busy(false); $('#impFile').value = ''; });
	}
	var previewTimer = null;
	function reloadPreview() {
		clearTimeout(previewTimer);
		previewTimer = setTimeout(function () {
			var r = read();
			if (r.delimiter === '') return;
			call('preview', r).then(showPreview).catch(function (e) { msg(e.message, 'danger'); });
		}, 150);
	}
	$('#impFile').addEventListener('change', function () { upload(this.files[0]); });
	var drop = $('#impDrop');
	['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragover'); }); });
	['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('dragover'); }); });
	drop.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) upload(e.dataTransfer.files[0]); });
	$('#impDelimiter').addEventListener('change', function () {
		var custom = this.value === 'custom';
		$('#impDelimiterCustom').classList.toggle('d-none', !custom);
		if (custom) $('#impDelimiterCustom').focus(); else reloadPreview();
	});
	$('#impDelimiterCustom').addEventListener('input', function () { if (this.value.length === 1) reloadPreview(); });
	$('#impQuote').addEventListener('change', reloadPreview);
	$('#impHeader').addEventListener('change', reloadPreview);

	// ------------------------------------------------------------------ step 2: table and mapping
	var tableSel = window.TomSelect ? new TomSelect('#impTable', {options: TABLES.map(function (t) { return {value: t, text: t === USERS ? tx('imp_u_target') : t}; }), maxOptions: 1000, create: false, allowEmptyOption: true}) : null;
	if (!tableSel) TABLES.forEach(function (t) { $('#impTable').insertAdjacentHTML('beforeend', '<option value="' + esc(t) + '">' + esc(t === USERS ? tx('imp_u_target') : t) + '</option>'); });
	function setTable(t) { if (tableSel) tableSel.setValue(t, true); else $('#impTable').value = t; }

	function loadColumns(table, then) {
		if (!table) return;
		busy(true);
		call('columns', {table: table}).then(function (j) {
			S.table = table; S.columns = j.columns; S.mapping = {};
			$$('.impTableOnly', app).forEach(function (el) { el.classList.toggle('d-none', isUsers()); });
			$$('.impUsersOnly', app).forEach(function (el) { el.classList.toggle('d-none', !isUsers()); });
			if (isUsers()) { $('#impMode').value = 'insert'; $('#impKeyGroup').classList.add('d-none'); }   // users: new logins only
			buildMapping();
			$('#impMapCard').classList.remove('d-none'); $('#impOptCard').classList.remove('d-none');
			if (then) then(); else if (S.config && S.config.table === table) applyMapConfig(); else autoMap(true);
		}).catch(function (e) { msg(e.message, 'danger'); }).finally(function () { busy(false); });
	}
	if (tableSel) tableSel.on('change', function (v) { loadColumns(v); }); else $('#impTable').addEventListener('change', function () { loadColumns(this.value); });

	function sourceOptions(c) {
		var o = '<option value="">' + esc(tx('imp_none')) + '</option>';
		S.headers.forEach(function (h, i) { o += '<option value="csv:' + i + '">' + esc(h) + '</option>'; });
		o += '<option value="const">' + esc(tx('imp_const')) + '</option>';
		if (c.nuid) o += '<option value="nuid">' + esc(tx('imp_nuid')) + '</option>';
		return o;
	}
	function formatOptions() {
		var o = '<option value="">' + esc(tx('imp_fmt_auto')) + '</option>';
		Object.keys(FORMATS).forEach(function (f) {
			var label = f === 'timestamp' ? tx('imp_fmt_timestamp') : f === 'excel' ? tx('imp_fmt_excel') : f.replace('\\T', 'T');
			o += '<option value="' + esc(f) + '">' + esc(label) + ' – ' + esc(FORMATS[f]) + '</option>';
		});
		return o;
	}
	function buildMapping() {
		S.builtFor = JSON.stringify(S.headers);            // mapping rows belong to these CSV columns
		var body = $('#impMapBody'), fopts = formatOptions();
		body.innerHTML = S.columns.map(function (c) {
			var badges = (c.pk ? '<span class="badge nuFormType nuFormType-launch me-1">' + esc(tx('imp_pk')) + '</span>' : '')
				+ (c.auto ? '<span class="badge nuFormType nuFormType-browse me-1">' + esc(tx('imp_auto')) + '</span>' : '')
				+ (c.required ? '<span class="badge bg-danger-subtle text-danger-emphasis me-1">' + esc(tx('imp_required')) + '</span>' : '');
			var date = c.kind === 'date' || c.kind === 'datetime' || c.kind === 'time';
			return '<tr data-col="' + esc(c.name) + '">'
				+ '<td><div class="fw-semibold">' + esc(c.name) + '</div><div class="small text-body-secondary">' + esc(c.type) + '</div>' + badges + '</td>'
				+ '<td class="impSrcCell"><select class="form-select form-select-sm impSrc" aria-label="' + esc(c.name) + '">' + sourceOptions(c) + '</select>'
				+ (c.choices ? '<select class="form-select form-select-sm mt-1 impConst d-none" aria-label="' + esc(tx('imp_const')) + '"><option value="">' + esc(tx('imp_u_choose_level')) + '</option>'
					+ c.choices.map(function (o) { return '<option value="' + esc(o) + '">' + esc(o) + '</option>'; }).join('') + '</select>'
					: '<input type="text" class="form-control form-control-sm mt-1 impConst d-none" aria-label="' + esc(tx('imp_const')) + '">') + '</td>'
				+ '<td>' + (date ? '<select class="form-select form-select-sm impFmt" aria-label="' + esc(c.name) + '">' + fopts + '</select><div class="small text-success impFmtInfo"></div>' : '<span class="text-body-secondary small">–</span>') + '</td>'
				+ '<td class="impPrev small"></td></tr>';
		}).join('');
		var key = $('#impKey');
		key.innerHTML = S.columns.map(function (c) { return '<option value="' + esc(c.name) + '"' + (c.pk ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('');
	}
	function rowOf(col) { return $('#impMapBody tr[data-col="' + CSS.escape(col) + '"]'); }
	function setSource(col, src, value, fmt, auto) {
		var tr = rowOf(col);
		if (!tr) return;
		var sel = $('.impSrc', tr), cst = $('.impConst', tr), f = $('.impFmt', tr);
		sel.value = src || '';
		cst.classList.toggle('d-none', sel.value !== 'const');
		if (value !== undefined) cst.value = value;
		sel.classList.toggle('impAutoMapped', !!auto && !!src);
		if (f) {
			if (fmt !== undefined) f.value = fmt;
			var m = /^csv:(\d+)$/.exec(sel.value), det = m ? S.dates[+m[1]] : '';
			if (m && fmt === undefined && det && !f.value) f.value = det;
			$('.impFmtInfo', tr).textContent = m && det && f.value === det ? tx('imp_detected') : '';
		}
		collect();
	}
	function collect() {
		S.mapping = {};
		$$('#impMapBody tr').forEach(function (tr) {
			var v = $('.impSrc', tr).value, f = $('.impFmt', tr), m = /^csv:(\d+)$/.exec(v);
			if (m) S.mapping[tr.dataset.col] = {src: 'csv', idx: +m[1], header: S.headers[+m[1]], fmt: f ? f.value : ''};
			else if (v === 'const') S.mapping[tr.dataset.col] = {src: 'const', value: $('.impConst', tr).value, fmt: f ? f.value : ''};
			else if (v === 'nuid') S.mapping[tr.dataset.col] = {src: 'nuid'};
		});
		refreshSample();
	}
	$('#impMapBody').addEventListener('change', function (e) {
		var tr = e.target.closest('tr');
		if (e.target.classList.contains('impSrc')) setSource(tr.dataset.col, e.target.value, undefined, undefined, false);
		else collect();
	});
	$('#impMapBody').addEventListener('input', function (e) { if (e.target.classList.contains('impConst')) collect(); });
	['#impDecimal', '#impEmpty', '#impTrim'].forEach(function (s) { $(s).addEventListener('change', refreshSample); });
	$('#impMode').addEventListener('change', function () { $('#impKeyGroup').classList.toggle('d-none', this.value === 'insert'); });

	var sampleTimer = null, sampleSeq = 0;
	function refreshSample() {
		clearTimeout(sampleTimer);
		sampleTimer = setTimeout(function () {
			var my = ++sampleSeq;
			$$('#impMapBody .impPrev').forEach(function (td) { if (!S.mapping[td.parentNode.dataset.col]) td.innerHTML = ''; });
			if (!S.table || !Object.keys(S.mapping).length) return;
			call('sample', Object.assign({table: S.table, mapping: S.mapping}, read(), options())).then(function (j) {
				if (my !== sampleSeq) return;
				Object.keys(j.sample).forEach(function (col) {
					var tr = rowOf(col);
					if (!tr) return;
					$('.impPrev', tr).innerHTML = j.sample[col].map(function (p) {
						return p[1] ? '<div class="text-danger" title="' + esc(p[1]) + '"><i class="bi bi-x-circle me-1"></i>' + esc(p[1]) + '</div>' : '<div class="text-truncate impPrevVal">' + esc(p[0]) + '</div>';
					}).join('');
				});
			}).catch(function () {});
		}, 250);
	}

	// ------------------------------------------------------------------ auto-mapping (column names, common prefix, synonyms, similarity)
	var SYN = {
		firstname: ['fname', 'first', 'givenname', 'vorname', 'prenom'], lastname: ['lname', 'last', 'surname', 'familyname', 'nachname', 'familienname', 'nom'],
		name: ['fullname', 'displayname', 'bezeichnung', 'name1'], email: ['emailaddress', 'mail', 'email', 'emailadresse', 'courriel'],
		phone: ['phonenumber', 'tel', 'telephone', 'mobile', 'telefon', 'natel', 'handy'], fax: ['faxnumber', 'telefax'],
		address: ['addr', 'street', 'strasse', 'adresse', 'address1'], city: ['town', 'ort', 'stadt', 'ville'], zip: ['zipcode', 'postalcode', 'postcode', 'plz', 'npa'],
		state: ['province', 'region', 'kanton', 'bundesland'], country: ['nation', 'land', 'pays', 'countrycode'],
		birthdate: ['dob', 'dateofbirth', 'birthday', 'geburtsdatum'], company: ['organization', 'organisation', 'org', 'firma', 'unternehmen'],
		department: ['dept', 'division', 'abteilung'], position: ['jobtitle', 'title', 'funktion', 'role'], description: ['desc', 'beschreibung', 'notes', 'notizen', 'remarks', 'bemerkung', 'comment', 'kommentar'],
		status: ['state', 'zustand'], active: ['enabled', 'aktiv', 'isactive'], type: ['category', 'kind', 'kategorie', 'typ', 'art'],
		amount: ['total', 'sum', 'betrag', 'summe', 'value', 'wert'], price: ['preis', 'cost', 'kosten', 'unitprice'], quantity: ['qty', 'count', 'menge', 'anzahl'],
		code: ['number', 'nr', 'no', 'nummer', 'key', 'ref', 'reference', 'kundennummer', 'kundennr', 'customerno', 'customernumber', 'artikelnummer', 'artikelnr', 'itemno'], date: ['datum'], created: ['createdat', 'createdon', 'erstellt', 'datecreated'],
		updated: ['updatedat', 'modified', 'modifiedat', 'geaendert', 'lastmodified'], website: ['url', 'web', 'homepage', 'www', 'site'], language: ['lang', 'sprache'],
		loginname: ['login', 'username', 'user', 'userid', 'benutzer', 'benutzername', 'anmeldename', 'identifiant'], password: ['passwort', 'pw', 'pwd', 'pass', 'kennwort', 'motdepasse'],
		accesslevel: ['access', 'level', 'accesscode', 'zugriff', 'zugriffsebene', 'zugriffsstufe', 'niveau'], expireson: ['expires', 'expiry', 'gultigbis', 'expiration', 'validuntil', 'gueltigbis', 'ablauf', 'ablaufdatum', 'enddate'],
		credit: ['creditlimit', 'kredit', 'kreditlimite', 'kreditlimit', 'limit'], since: ['customersince', 'kundeseit', 'seit', 'startdate'], note: ['notes', 'notiz', 'bemerkung']
	};
	function norm(s) {
		return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, '');
	}
	function prefix(cols) {                 // nuBuilder habit: all columns start with "cus_", "ord_" …
		var parts = cols.map(function (c) { var m = /^([a-z0-9]{2,5})_/i.exec(c.name); return m ? m[1].toLowerCase() + '_' : ''; }).filter(Boolean);
		if (parts.length < Math.max(2, cols.length / 2)) return '';
		var freq = {}; parts.forEach(function (p) { freq[p] = (freq[p] || 0) + 1; });
		var best = Object.keys(freq).sort(function (a, b) { return freq[b] - freq[a]; })[0];
		return freq[best] >= parts.length / 2 ? best : '';
	}
	function lev(a, b) {
		var m = [], i, j;
		for (i = 0; i <= b.length; i++) m[i] = [i];
		for (j = 0; j <= a.length; j++) m[0][j] = j;
		for (i = 1; i <= b.length; i++) for (j = 1; j <= a.length; j++) m[i][j] = b[i - 1] === a[j - 1] ? m[i - 1][j - 1] : Math.min(m[i - 1][j - 1], m[i][j - 1], m[i - 1][j]) + 1;
		return m[b.length][a.length];
	}
	function group(n) { var g = n; Object.keys(SYN).forEach(function (k) { if (k === n || SYN[k].indexOf(n) !== -1) g = k; }); return g; }
	/** A synonym of the column inside a compound header: "Kreditlimite" ⊃ "kredit", "Kunde seit" ⊃ "seit". */
	function synInside(d, c) {
		var g = group(d), words = [g].concat(SYN[g] || []);
		return words.some(function (w) { return w.length >= 4 && c.indexOf(w) !== -1; });
	}
	function score(dbName, pre, csvName, label) {
		if (label && norm(label) && norm(label) === norm(csvName)) return 1;   // column title of the user import template
		var raw = norm(dbName), d = norm(pre && dbName.toLowerCase().indexOf(pre) === 0 ? dbName.slice(pre.length) : dbName), c = norm(csvName);
		if (!c) return 0;
		if (c === raw) return 1;
		if (c === d) return 0.97;
		var ds = d.replace(/(id|code|nr|no)$/, ''), cs = c.replace(/(id|code|nr|no)$/, '');
		if (ds && ds === cs) return 0.9;
		if (group(d) === group(c)) return 0.88;
		if (synInside(d, c)) return 0.78;
		if (d.length > 2 && c.length > 2 && (c.indexOf(d) !== -1 || d.indexOf(c) !== -1)) return 0.75 * Math.min(d.length, c.length) / Math.max(d.length, c.length) + 0.1;
		var sim = 1 - lev(d, c) / Math.max(d.length, c.length);
		return sim >= 0.75 ? sim * 0.8 : 0;
	}
	function autoMap(quiet) {
		if (!S.columns.length) return;
		var pre = prefix(S.columns), used = {}, pairs = [];
		S.columns.forEach(function (c) { if (!c.auto) S.headers.forEach(function (h, i) { var s = score(c.name, pre, h, c.label); if (s >= 0.6) pairs.push([s, c, i]); }); });
		pairs.sort(function (a, b) { return b[0] - a[0]; });                       // best pairs first, each CSV column once
		var done = {}, n = 0;
		pairs.forEach(function (p) {
			if (done[p[1].name] || used[p[2]]) return;
			done[p[1].name] = used[p[2]] = true; n++;
			setSource(p[1].name, 'csv:' + p[2], undefined, undefined, true);
		});
		S.columns.forEach(function (c) { if (!done[c.name] && c.nuid && c.pk) { setSource(c.name, 'nuid', undefined, undefined, true); n++; } });   // nuBuilder ids
		collect();
		if (!quiet || n) msg(n ? tx('imp_auto_mapped', n) : tx('imp_nothing_mapped'), n ? 'success' : 'warning');
	}

	// ------------------------------------------------------------------ configuration (JSON)
	function configObject() {
		return {nunextImport: 2, created: new Date().toISOString(), table: S.table, read: read(), options: options(), mapping: S.mapping, headers: S.headers};
	}
	function applyReadConfig() {
		var r = S.config.read || {};
		if (r.delimiter) {
			var known = $('#impDelimiter option[value="' + CSS.escape(r.delimiter) + '"]');
			$('#impDelimiter').value = known ? r.delimiter : 'custom';
			$('#impDelimiterCustom').classList.toggle('d-none', !!known);
			if (!known) $('#impDelimiterCustom').value = r.delimiter;
		}
		if (r.quote) $('#impQuote').value = r.quote;
		if (r.header !== undefined) $('#impHeader').checked = r.header !== '0';
		reloadPreview();
	}
	function applyMapConfig() {
		var c = S.config, o = c.options || {}, n = 0;
		Object.keys(c.mapping || {}).forEach(function (col) {
			var m = c.mapping[col];
			if (!rowOf(col)) return;
			if (m.src === 'csv') {
				var idx = m.header !== undefined ? S.headers.indexOf(m.header) : -1;   // by header name, else by position
				if (idx < 0 && m.idx < S.headers.length && !(c.headers && c.headers.length)) idx = m.idx;
				if (idx >= 0) { setSource(col, 'csv:' + idx, undefined, m.fmt || '', false); n++; }
			} else if (m.src === 'const') { setSource(col, 'const', m.value || '', m.fmt || '', false); n++; }
			else if (m.src === 'nuid') { setSource(col, 'nuid', undefined, undefined, false); n++; }
		});
		['mode', 'key', 'decimal', 'empty'].forEach(function (k) { if (o[k] !== undefined && $('#imp' + k.charAt(0).toUpperCase() + k.slice(1))) $('#imp' + k.charAt(0).toUpperCase() + k.slice(1)).value = o[k]; });
		if (o.on_error) $('#impOnError').value = o.on_error;
		if (o.trim !== undefined) $('#impTrim').checked = o.trim !== '0';
		if (o.audit !== undefined) $('#impAudit').checked = o.audit !== '0';
		if (isUsers()) $('#impMode').value = 'insert';
		$('#impKeyGroup').classList.toggle('d-none', $('#impMode').value === 'insert');
		collect();
		msg(tx('imp_config_applied', n), 'success');
	}
	$('#impConfigFile').addEventListener('change', function () {
		var f = this.files[0], input = this;
		if (!f) return;
		f.text().then(function (txt) {
			var c = JSON.parse(txt);
			if (!c || typeof c !== 'object' || !c.mapping) throw new Error('format');
			S.config = c;
			$('#impConfigInfo').textContent = tx('imp_config_loaded', f.name + (c.table ? ' → ' + c.table : ''));
			if (S.headers.length) applyReadConfig();
		}).catch(function () { msg(tx('imp_config_bad'), 'danger'); }).finally(function () { input.value = ''; });
	});
	function download(name, text, type) {
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([text], {type: type}));
		a.download = name;
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
	}

	// ------------------------------------------------------------------ step 3: check and import
	function card(label, value, cls) {
		return '<div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="small text-body-secondary">' + esc(label)
			+ '</div><div class="fs-3 fw-semibold ' + cls + '">' + Number(value).toLocaleString() + '</div></div></div></div>';
	}
	function showResult(j, imported) {
		S.lastResult = j;
		var users = isUsers();
		$('#impSummary').innerHTML = users
			? card(tx('imp_sum_insert'), j.inserted, 'text-success') + card(tx('imp_sum_skipped'), j.skipped, j.skipped ? 'text-info' : 'text-body-secondary')
				+ card(tx('imp_u_welcome_n'), j.welcome, j.welcome ? 'text-primary' : 'text-body-secondary') + card(tx('imp_sum_failed'), j.failed, j.failed ? 'text-danger' : 'text-body-secondary')
			: card(tx('imp_sum_insert'), j.inserted, 'text-success') + card(tx('imp_sum_update'), j.updated, 'text-primary')
				+ card(tx('imp_sum_unchanged'), j.unchanged, 'text-body-secondary') + card(tx('imp_sum_failed'), j.failed, j.failed ? 'text-danger' : 'text-body-secondary');
		var sk = $('#impSkipped');
		sk.classList.toggle('d-none', !(users && (j.skipped || j.random)));
		sk.innerHTML = users ? (j.skipped ? '<div><i class="bi bi-person-dash me-1"></i>' + esc(tx('imp_u_skipped', j.skipped)) + '</div><div class="small mt-1 text-break">'
			+ (j.skipped_logins || []).map(function (s) { return esc(s.login) + ' <span class="text-body-secondary">(' + s.line + ')</span>'; }).join(', ') + '</div>' : '')
			+ (j.random ? '<div class="small' + (j.skipped ? ' mt-2' : '') + '"><i class="bi bi-key me-1"></i>' + esc(tx('imp_u_random_n', j.random)) + '</div>' : '') : '';
		var res = $('#impResult'), stop = $('#impOnError').value === 'stop';
		res.className = 'alert';
		if (imported) {
			res.classList.add(j.rolled_back ? 'alert-danger' : 'alert-success');
			res.textContent = j.rolled_back ? tx('imp_rolled_back') : users ? tx('imp_u_done', j.inserted.toLocaleString()) : tx('imp_done', (j.inserted + j.updated).toLocaleString(), S.table);
		} else {
			res.classList.add(j.failed ? (stop ? 'alert-danger' : 'alert-warning') : 'alert-success');
			res.textContent = j.failed ? tx('imp_check_errors', j.failed.toLocaleString()) : tx('imp_check_ok', j.rows.toLocaleString());
		}
		var eb = $('#impErrBody');
		eb.innerHTML = j.errors.map(function (e) {
			return '<tr><td>' + e.line + '</td><td>' + esc(e.column) + '</td><td class="text-break">' + esc(e.value) + '</td><td class="text-danger">' + esc(e.message) + '</td></tr>';
		}).join('') + (j.failed > j.errors.length ? '<tr><td colspan="4" class="text-body-secondary">' + esc(tx('imp_more_errors', j.errors.length)) + '</td></tr>' : '');
		$('#impErrCard').classList.toggle('d-none', !j.errors.length);
		var done = imported && !j.rolled_back;
		$('#impGo').classList.toggle('d-none', done);
		$('#impGo').disabled = !imported && j.failed > 0 && stop || (j.inserted + j.updated === 0);
		$('#impNew').classList.toggle('d-none', !done);
		$('#impSaveAfter').classList.toggle('d-none', !done);
		$$('[data-imp="to2"]', $('.impStep[data-step="3"]')).forEach(function (b) { b.classList.toggle('d-none', done); });
	}
	function runImport(action) {
		if (!S.table) { msg(tx('imp_choose_table'), 'warning'); return; }
		if (!Object.keys(S.mapping).length) { msg(tx('imp_nothing_mapped'), 'warning'); return; }
		if ($('#impMode').value !== 'insert' && !S.mapping[$('#impKey').value]) { msg(tx('imp_key_needed'), 'warning'); return; }
		msg(tx('imp_working'), 'info');
		busy(true);
		call(action, Object.assign({table: S.table, mapping: S.mapping}, read(), options())).then(function (j) {
			busy(false);                                      // first: the result decides which buttons stay disabled
			msg('');
			step(3);
			showResult(j, action === 'import');
		}).catch(function (e) { busy(false); msg(e.message, 'danger'); });
	}

	// ------------------------------------------------------------------ buttons
	app.addEventListener('click', function (e) {
		var b = e.target.closest('[data-imp]');
		if (!b || b.disabled) return;
		var a = b.getAttribute('data-imp');
		if (a === 'to1') step(1);
		else if (a === 'to2') {
			if (!S.headers.length) return;
			step(2);
			if (!S.table && S.config && S.config.table && TABLES.indexOf(S.config.table) !== -1) { setTable(S.config.table); loadColumns(S.config.table); }
			else if (!S.table && TARGET && TABLES.indexOf(TARGET) !== -1) { setTable(TARGET); loadColumns(TARGET); }   // users list → Import…
			else if (S.table && S.builtFor !== JSON.stringify(S.headers)) {   // other columns (separator, header row): rebuild, keep what still fits
				var keep = S.mapping, key = $('#impKey').value;
				buildMapping();
				Object.keys(keep).forEach(function (c) { var m = keep[c], i = m.src === 'csv' ? S.headers.indexOf(m.header) : -1; setSource(c, m.src === 'csv' ? (i >= 0 ? 'csv:' + i : '') : m.src, m.value, m.fmt, false); });
				if (key) $('#impKey').value = key;
			}
		}
		else if (a === 'automap') autoMap(false);
		else if (a === 'clear') { $$('#impMapBody tr').forEach(function (tr) { setSource(tr.dataset.col, '', '', '', false); }); msg(tx('imp_cleared'), 'info'); }
		else if (a === 'check') runImport('check');
		else if (a === 'import') { if (window.confirm(b.textContent.trim() + '?')) runImport('import'); }
		else if (a === 'saveconfig') download('csv-import-' + (S.table || 'config') + '.json', JSON.stringify(configObject(), null, 2), 'application/json');
		else if (a === 'errcsv' && S.lastResult) {
			var q = function (v) { v = String(v); return /[";\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
			var lines = [[tx('imp_line'), tx('imp_column'), tx('imp_value'), tx('imp_message')].map(q).join(';')]
				.concat(S.lastResult.errors.map(function (er) { return [er.line, er.column, er.value, er.message].map(q).join(';'); }));
			download('csv-import-errors.csv', '﻿' + lines.join('\r\n'), 'text/csv');
		}
		else if (a === 'restart') {
			call('discard', {}).catch(function () {});
			S.headers = []; S.rows = []; S.table = ''; S.mapping = {}; S.columns = [];
			setTable(''); $('#impMapBody').innerHTML = '';
			['#impFileCard', '#impMapCard', '#impOptCard'].forEach(function (s) { $(s).classList.add('d-none'); });
			msg(''); step(1);
		}
	});
})();
