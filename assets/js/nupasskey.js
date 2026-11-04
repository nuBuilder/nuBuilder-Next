/*
 * Passkeys (0.8.877) – buttons [data-nu-pk="add|2fa|login|reauth"] on the security page, the second-factor page, the sign-in page
 * and the password confirmation. Options and checks come from index.php?p=passkey (pages/nupasskey.php, core/nupasskey.php); binary
 * values travel base64url. Texts: data-t-cancel / data-t-unsupported / data-t-exists / data-t-failed on the button; the message goes
 * into the element of data-err. The sign-in page also offers the passkeys in the user name field (conditional mediation) when its
 * input has autocomplete="username webauthn".
 */
(function () {
	'use strict';
	var supported = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);

	function b64(buf) {
		var s = '', bytes = new Uint8Array(buf);
		for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
		return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
	function buf(s) {
		s = String(s || '').replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) s += '=';
		var bin = atob(s), out = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
		return out.buffer;
	}
	function credJSON(c) {
		var r = c.response, o = {id: c.id, rawId: b64(c.rawId), type: c.type, response: {clientDataJSON: b64(r.clientDataJSON)}};
		if (r.attestationObject) {
			o.response.attestationObject = b64(r.attestationObject);
			o.response.transports = typeof r.getTransports === 'function' ? r.getTransports() : [];
		}
		if (r.authenticatorData) {
			o.response.authenticatorData = b64(r.authenticatorData);
			o.response.signature = b64(r.signature);
			o.response.userHandle = r.userHandle ? b64(r.userHandle) : '';
		}
		return o;
	}
	function csrf() {
		var i = document.querySelector('input[name="_csrf"]');
		return i ? i.value : '';
	}
	function post(url, action, extra) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('_csrf', csrf());
		Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
		return fetch(url, {method: 'POST', body: fd, credentials: 'same-origin', headers: {Accept: 'application/json'}})
			.then(function (r) { return r.json().catch(function () { return {ok: false, message: 'HTTP ' + r.status}; }); });
	}
	function errBox(btn) {
		var sel = btn.getAttribute('data-err');
		return sel ? document.querySelector(sel) : null;
	}
	function showError(btn, text) {
		var box = errBox(btn);
		if (!box) return;
		box.textContent = text || '';
		box.hidden = !text;
	}
	function busy(btn, on) {
		btn.disabled = on;
		btn.classList.toggle('nuPkBusy', on);
		btn.setAttribute('aria-busy', on ? 'true' : 'false');
	}
	function domError(btn, e) {
		var n = e && e.name;
		if (n === 'NotAllowedError' || n === 'AbortError') return btn.getAttribute('data-t-cancel') || String(e.message || e);
		if (n === 'InvalidStateError') return btn.getAttribute('data-t-exists') || String(e.message || e);
		if (n === 'SecurityError' || n === 'NotSupportedError') return btn.getAttribute('data-t-unsupported') || String(e.message || e);
		return (btn.getAttribute('data-t-failed') || '') + (e && e.message ? ' (' + e.message + ')' : '');
	}
	/** An answer of the server: ok + go = on to that page (true); else the message, and go after a moment when given (false). */
	function follow(r, btn) {
		if (r && r.ok && r.go) { window.location.href = r.go; return true; }
		showError(btn, (r && r.message) || btn.getAttribute('data-t-failed'));
		if (r && r.go) setTimeout(function () { window.location.href = r.go; }, 1500);
		return false;
	}

	var conditional = null;                                   // AbortController of the passkey offer in the user name field

	function getOpts(o) {
		o.challenge = buf(o.challenge);
		(o.allowCredentials || []).forEach(function (c) { c.id = buf(c.id); });
		return o;
	}

	function run(btn) {
		var kind = btn.getAttribute('data-nu-pk'), url = btn.getAttribute('data-url');
		showError(btn, '');
		if (!supported) { showError(btn, btn.getAttribute('data-t-unsupported')); return; }
		if (conditional) { conditional.abort(); conditional = null; }
		busy(btn, true);
		var prefix = kind === 'add' ? 'reg' : (kind === '2fa' ? 'twofa' : kind);
		var extra = {};
		post(url, prefix + '_options').then(function (r) {
			if (!r || !r.ok || !r.options) { if (!follow(r, btn)) busy(btn, false); return null; }
			var o = r.options;
			if (kind === 'add') {
				o.challenge = buf(o.challenge);
				o.user.id = buf(o.user.id);
				(o.excludeCredentials || []).forEach(function (c) { c.id = buf(c.id); });
				return navigator.credentials.create({publicKey: o});
			}
			return navigator.credentials.get({publicKey: getOpts(o)});
		}).then(function (cred) {
			if (!cred) return null;
			extra.cred = JSON.stringify(credJSON(cred));
			if (kind === 'add') {
				var name = document.getElementById(btn.getAttribute('data-name') || 'pkName');
				extra.name = name ? name.value : '';
			}
			var trust = document.getElementById('trust');
			if (kind === '2fa' && trust && trust.checked) extra.trust = '1';
			return post(url, prefix, extra);
		}).then(function (r) {
			if (r === null || r === undefined) return;
			if (!follow(r, btn)) busy(btn, false);
		}).catch(function (e) {
			busy(btn, false);
			showError(btn, domError(btn, e));
		});
	}

	/** The sign-in page: passkeys offered in the user name field's autofill list. */
	function startConditional(btn) {
		var input = document.querySelector('input[autocomplete~="webauthn"]');
		if (!input || !supported || typeof PublicKeyCredential.isConditionalMediationAvailable !== 'function' || typeof AbortController === 'undefined') return;
		var url = btn.getAttribute('data-url');
		PublicKeyCredential.isConditionalMediationAvailable().then(function (ok) {
			if (!ok) return null;
			return post(url, 'login_options').then(function (r) {
				if (!r || !r.ok || !r.options) return null;
				conditional = new AbortController();
				return navigator.credentials.get({publicKey: getOpts(r.options), mediation: 'conditional', signal: conditional.signal});
			});
		}).then(function (cred) {
			if (!cred) return null;
			conditional = null;
			busy(btn, true);
			return post(url, 'login', {cred: JSON.stringify(credJSON(cred))}).then(function (r) { if (!follow(r, btn)) busy(btn, false); });
		}).catch(function (e) {
			if (!(e && e.name === 'AbortError')) busy(btn, false);   // aborted: the button's own request is running
			conditional = null;
			// nothing shown: the user did not ask for it (AbortError = the button was used instead); the button still works
			if (e && e.name !== 'AbortError' && window.console) console.info('nuBuilder Next: passkey offer in the user name field –', e.name || e);
		});
	}

	document.querySelectorAll('[data-nu-pk]').forEach(function (btn) {
		if (!supported) {
			var note = document.querySelector(btn.getAttribute('data-unsupported') || '#nuPkUnsupported');
			if (note) note.hidden = false;
			btn.disabled = true;
		}
		btn.addEventListener('click', function (e) { e.preventDefault(); run(btn); });
		if (btn.getAttribute('data-nu-pk') === 'login') startConditional(btn);
	});
})();
