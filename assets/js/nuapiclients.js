/* REST API clients (pages/nuapiclients.php): new service user fields, copy buttons. */
(function () {
	'use strict';
	// service user (0.8.530): New service user (login name, display name, access level) or Existing user (searchable list + its access level)
	var sel = document.getElementById('user_id'), boxNew = document.querySelector('.nuApiNewUser'), boxOld = document.querySelector('.nuApiExistUser');
	var info = document.getElementById('apiUserAccess');
	if (sel && boxNew && boxOld) {
		var mode = function () { var r = document.querySelector('input[name="su_mode"]:checked'); return r ? r.value : 'new'; };
		var showAccess = function () {
			if (!info) return;
			var o = sel.options[sel.selectedIndex], a = o && o.value ? (o.getAttribute('data-access') || '') : null;
			info.classList.toggle('text-danger', a === '');
			info.textContent = a === null ? '' : a === '' ? sel.getAttribute('data-noaccess-text') : sel.getAttribute('data-access-text').replace('%s', a);
		};
		var sync = function () {
			var m = mode();
			boxNew.hidden = m !== 'new';
			boxOld.hidden = m !== 'existing';
		};
		document.querySelectorAll('input[name="su_mode"]').forEach(function (r) { r.addEventListener('change', sync); });
		sel.addEventListener('change', showAccess);
		sync();
		showAccess();
	}
	document.querySelectorAll('.nuApiCopy').forEach(function (b) {
		b.addEventListener('click', function () {
			var f = document.querySelector(b.getAttribute('data-copy'));
			if (!f) return;
			var ok = function () { var i = b.querySelector('i'); i.className = 'bi bi-check2'; setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(f.value).then(ok, function () { f.select(); });
			else { f.select(); try { document.execCommand('copy'); ok(); } catch (e) { /* ignore */ } }
		});
	});
	// "Generate key" dialog ($nuConfigSecretKey): 32 random bytes as 64 hex characters, made in the browser (crypto.getRandomValues)
	var keyModal = document.getElementById('nuKeyGen'), keyField = document.getElementById('nuKeyValue');
	if (keyModal && keyField) {
		var newKey = function () {
			var b = new Uint8Array(32);
			window.crypto.getRandomValues(b);
			keyField.value = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
		};
		keyModal.addEventListener('show.bs.modal', function () { if (!keyField.value) newKey(); });
		keyModal.addEventListener('shown.bs.modal', function () { keyField.focus(); keyField.select(); });
		document.getElementById('nuKeyNew').addEventListener('click', newKey);
		document.getElementById('nuKeyCopy').addEventListener('click', function () {
			var b = this, ok = function () { var i = b.querySelector('i'); i.className = 'bi bi-check2'; setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(keyField.value).then(ok, function () { keyField.select(); });
			else { keyField.select(); try { document.execCommand('copy'); ok(); } catch (e) { /* ignore */ } }
		});
	}
	document.querySelectorAll('#apiSecret input[readonly]').forEach(function (f) { f.addEventListener('focus', function () { f.select(); }); });
})();
