/* nuBuilder Next – E-mail settings (pages/nuemail.php): copy the SMTP settings as JSON to the clipboard and paste them back –
 * e.g. from another installation. The password is never copied and never taken over. Without the clipboard API (http without TLS,
 * permission refused) copy falls back to a selection and paste to a dialog with a text field. */
(function () {
	'use strict';
	var form = document.getElementById('nuMailForm');
	if (!form) return;
	var copyBtn = document.getElementById('nuMailCopy'), pasteBtn = document.getElementById('nuMailPaste');
	var status = document.getElementById('nuMailClipStatus');
	var FIELDS = { host: 'm_host', port: 'm_port', username: 'm_user', from_address: 'm_from', from_name: 'm_fromname', auth_type: 'm_authtype' };   // auth_type: 0.8.342
	var SWITCHES = { use_authentication: 'm_auth', use_ssl: 'm_ssl' };
	var NU_KEYS = { set_smtp_host: 'host', set_smtp_port: 'port', set_smtp_username: 'username', set_smtp_from_address: 'from_address',
		set_smtp_from_name: 'from_name', set_smtp_use_authentication: 'use_authentication', set_smtp_use_ssl: 'use_ssl' };   // nuBuilder's column names

	// Save turns red on changes (typed or pasted), as on record pages
	var markDirty = function () { var b = form.querySelector('[data-nu-save]'); if (b) b.classList.add('nuSaveEdited'); };
	form.addEventListener('input', markDirty);
	form.addEventListener('change', markDirty);

	function say(text, type) {
		if (!status) return;
		status.textContent = text;
		status.className = 'small me-auto align-self-center text-' + (type || 'body-secondary');
	}

	function current() {
		var o = { type: 'nunext-smtp', version: 1 };
		Object.keys(FIELDS).forEach(function (k) { o[k] = document.getElementById(FIELDS[k]).value.trim(); });
		Object.keys(SWITCHES).forEach(function (k) { o[k] = document.getElementById(SWITCHES[k]).checked; });
		return o;
	}

	function bool(v) { return v === true || v === 1 || v === '1' || String(v).toLowerCase() === 'true' || String(v).toLowerCase() === 'yes'; }

	// JSON text → { field: value } or null; accepts our format and nuBuilder's set_smtp_* keys; any password key is ignored
	function parse(text) {
		var o;
		try { o = JSON.parse(String(text || '').trim()); } catch (e) { return null; }
		if (!o || typeof o !== 'object' || Array.isArray(o)) return null;
		var out = {}, n = 0;
		Object.keys(o).forEach(function (k) {
			var key = NU_KEYS[k] || k;
			if (FIELDS[key] || SWITCHES[key]) { out[key] = o[k]; n++; }
		});
		return n ? out : null;
	}

	function apply(values) {
		Object.keys(values).forEach(function (k) {
			var el = document.getElementById(FIELDS[k] || SWITCHES[k]);
			if (FIELDS[k]) el.value = values[k] === null || values[k] === undefined ? '' : String(values[k]);
			else el.checked = bool(values[k]);
			el.classList.remove('is-invalid');
			el.dispatchEvent(new Event('input', { bubbles: true }));   // Save turns red, the leave guard knows
			el.dispatchEvent(new Event('change', { bubbles: true }));
		});
		say(pasteBtn.getAttribute('data-pasted'), 'success');
	}

	function pasteText(text) {
		var v = parse(text);
		if (!v) { say(pasteBtn.getAttribute('data-invalid'), 'danger'); return false; }
		apply(v);
		return true;
	}

	if (copyBtn) copyBtn.addEventListener('click', function () {
		var text = JSON.stringify(current(), null, 2);
		var done = function (ok) { say(copyBtn.getAttribute(ok ? 'data-copied' : 'data-failed'), ok ? 'success' : 'danger'); };
		var fallback = function () {
			var ta = document.createElement('textarea');
			ta.value = text; ta.setAttribute('readonly', ''); ta.className = 'nuOffscreen';
			document.body.appendChild(ta); ta.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			ta.remove(); done(ok);
		};
		if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(function () { done(true); }, fallback);
		else fallback();
	});

	// download as a JSON file (0.8.629) – the same content as "Copy settings", without the password
	var dlBtn = document.getElementById('nuMailDownload');
	if (dlBtn) dlBtn.addEventListener('click', function () {
		var o = current(), a = document.createElement('a');
		var host = String(o.host || '').replace(/[^A-Za-z0-9.\-]+/g, '_');
		a.href = URL.createObjectURL(new Blob([JSON.stringify(o, null, 2) + '\n'], { type: 'application/json' }));
		a.download = 'smtp-settings' + (host ? '-' + host : '') + '.json';
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
		say(dlBtn.getAttribute('data-done'), 'success');
	});

	var modalEl = document.getElementById('nuMailPasteModal');
	var area = document.getElementById('nuMailPasteText');
	function dialog() {
		if (!modalEl || !nuBs.ready()) return;
		area.value = '';
		area.classList.remove('is-invalid');
		var m = nuBs.modal(modalEl);
		modalEl.addEventListener('shown.bs.modal', function () { area.focus(); }, { once: true });
		m.show();
	}
	if (modalEl) document.getElementById('nuMailPasteApply').addEventListener('click', function () {
		if (parse(area.value)) { pasteText(area.value); nuBs.modal(modalEl).hide(); }
		else area.classList.add('is-invalid');
	});

	if (pasteBtn) pasteBtn.addEventListener('click', function () {
		if (navigator.clipboard && navigator.clipboard.readText && window.isSecureContext) {
			navigator.clipboard.readText().then(function (text) { if (!pasteText(text)) dialog(); }, dialog);
		} else dialog();
	});
})();

/* OAuth 2 for the SMTP login (0.8.445): the block shows with the method OAuth 2; provider → tenant (Microsoft) or own addresses (other);
 * "Use smtp.gmail.com:587" fills server and port. */
(function () {
	'use strict';
	var box = document.getElementById('nuMailOAuth'), type = document.getElementById('m_authtype');
	if (!box || !type) return;
	var prov = document.getElementById('m_oprov'), srv = document.getElementById('nuMailOAuthServer');
	function sync() {
		box.classList.toggle('d-none', type.value !== 'XOAUTH2');
		var p = prov.value, opt = prov.options[prov.selectedIndex];
		box.querySelectorAll('.nuOAuthMs').forEach(function (e) { e.classList.toggle('d-none', p !== 'microsoft'); });
		box.querySelectorAll('.nuOAuthOther').forEach(function (e) { e.classList.toggle('d-none', p !== 'other'); });
		var host = opt && opt.getAttribute('data-host');
		srv.classList.toggle('d-none', !host);
		if (host) srv.textContent = srv.getAttribute('data-label').replace('%s', host + ':' + opt.getAttribute('data-port'));
	}
	type.addEventListener('change', sync);
	prov.addEventListener('change', sync);
	srv.addEventListener('click', function () {
		var opt = prov.options[prov.selectedIndex], h = document.getElementById('m_host'), pt = document.getElementById('m_port');
		if (!opt || !h || !pt) return;
		h.value = opt.getAttribute('data-host'); pt.value = opt.getAttribute('data-port');
		[h, pt].forEach(function (e) { e.dispatchEvent(new Event('input', {bubbles: true})); });
	});
	sync();
})();

