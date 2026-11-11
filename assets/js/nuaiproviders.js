/* AI providers (0.8.1099, pages/nuaiproviders.php): placeholders follow the kind, "Fetch models" fills the model list, the access
   choice shows its fields. */
(function () {
	'use strict';
	var form = document.getElementById('aipForm');
	if (!form) return;
	var K = {}, M = {};
	try { K = JSON.parse(form.getAttribute('data-kinds') || '{}'); M = JSON.parse(form.getAttribute('data-msg') || '{}'); } catch (e) { /* keep */ }
	var $ = function (id) { return document.getElementById(id); };
	var kind = $('aipKind');
	function syncKind() {
		var k = K[kind.value] || {};
		$('aipUrl').setAttribute('placeholder', k.url || '');
		$('aipModel').setAttribute('placeholder', k.model || '');
	}
	kind.addEventListener('change', function () { syncKind(); $('aipModels').textContent = ''; $('aipFetchMsg').textContent = ''; });
	function syncAccess() {
		var r = form.querySelector('.aipAccess:checked'), a = r ? r.value : 'all';
		$('aipLevelsBox').hidden = a !== 'levels';
		$('aipPwBox').hidden = a !== 'password';
	}
	Array.prototype.forEach.call(form.querySelectorAll('.aipAccess'), function (r) { r.addEventListener('change', syncAccess); });
	syncAccess();
	$('aipFetch').addEventListener('click', function () {
		var b = this, msg = $('aipFetchMsg'), csrf = form.querySelector('input[name=_csrf]');
		var body = new URLSearchParams();
		body.set('_csrf', csrf ? csrf.value : ''); body.set('action', 'models'); body.set('kind', kind.value);
		body.set('base_url', $('aipUrl').value); body.set('api_key', $('aipKey').value);
		b.disabled = true; msg.textContent = '…'; msg.className = 'form-text';
		fetch(b.getAttribute('data-url'), {method: 'POST', body: body, credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(function (r) { return r.json(); }).then(function (d) {
			var dl = $('aipModels');
			dl.textContent = '';
			(d.models || []).forEach(function (m) { var o = document.createElement('option'); o.value = m; dl.appendChild(o); });
			if (!d.ok) { msg.textContent = d.error || '?'; msg.className = 'form-text text-danger'; return; }
			msg.textContent = d.models.length ? String(M.n || '%d').replace('%d', d.models.length) : M.none;
			if (d.models.length) $('aipModel').focus();
		}).catch(function () { msg.textContent = '?'; msg.className = 'form-text text-danger'; }).then(function () { b.disabled = false; });
	});
})();
